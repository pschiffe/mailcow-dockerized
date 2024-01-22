<?php
/*
 * RoundCube Dovecot Client IP Plugin
 *
 * Copyright (C) 2021, Michael Maier
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Dovecot Client IP
 *
 * Provides the HTTP client IP during IMAP connect to Dovecot in the form of
 * an IMAP identifier for proper authentication penalty processing.
 *
 * Requires the webmail server to be added to login_trusted_networks in the
 * Dovecot configuration.
 *
 * Takes reverse proxy headers into account to determine the actual client IP.
 *
 * @author Michael Maier
 * @url https://gitlab.com/takerukoushirou/roundcube-dovecot_client_ip
 * @url https://doc.dovecot.org/settings/core/#login-trusted-networks
 * @url https://doc.dovecot.org/configuration_manual/authentication/auth_penalty/
 * @url https://doc.dovecot.org/configuration_manual/proxy_settings/
 */
class roundcube_dovecot_client_ip extends rcube_plugin
{

    /**
     * @url https://github.com/roundcube/roundcubemail/wiki/Plugin-Hooks#task-mail
     * @url https://github.com/roundcube/roundcubemail/wiki/Plugin-Hooks#task-login
     */
    // Both mail and login tasks invoke storage_connect callbacks.
    public $task = 'mail|login';

    const TrustedProxiesConfigKey = 'dovecot_client_ip_trusted_proxies';
    const ProxyAllowPrivateClientIpConfigKey = 'dovecot_client_ip_proxy_allow_private_client_ip';

    private $rc;

    public
    function init()
    {
        $this->rc = rcmail::get_instance();

        $this->load_config();

        $this->add_hook('storage_connect', [ $this, 'on_storage_connect' ]);
    }

    /**
     * Resolves the client IP by taking proxy headers into account.
     *
     * If REMOTE_ADDR matches one of the configured trusted proxies, common
     * proxy headers for forwarded client IPs are searched and the first valid
     * IP match is returned.
     *
     * By default, private range IP addresses are skipped unless explicitly
     * enabled in the configuration.
     *
     * @return string
     * Client IP address.
     */
    protected
    function get_client_ip()
    {
        $trusted_proxies = $this->rc->config->get(static::TrustedProxiesConfigKey);
        $allow_private_client_ip = $this->rc->config->get(static::ProxyAllowPrivateClientIpConfigKey);
        $remoteAddress = trim($_SERVER['REMOTE_ADDR']);

        if (is_array($trusted_proxies) and in_array($remoteAddress, $trusted_proxies)) {
            // Request originates from trusted proxy. Process common headers.
            $client_headers = [
                'HTTP_CLIENT_IP',
                'HTTP_X_FORWARDED_FOR',
                'HTTP_X_FORWARDED',
                'HTTP_X_CLUSTER_CLIENT_IP',
                'HTTP_FORWARDED_FOR',
                'HTTP_FORWARDED',
                'REMOTE_ADDR',
            ];
            $filter_flags = FILTER_FLAG_NO_RES_RANGE;

            if (!$allow_private_client_ip) {
                $filter_flags |= FILTER_FLAG_NO_PRIV_RANGE;
            }

            foreach ($client_headers as $header_key) {
                if (!empty($_SERVER[$header_key])) {
                    $ips = explode(',', $_SERVER[$header_key]);

                    foreach ($ips as $ip) {
                        $ip = trim($ip);

                        if (false !== filter_var($ip, FILTER_VALIDATE_IP, $filter_flags)) {
                            return $ip;
                        }
                    }
                }
            }
        }

        if (empty($remoteAddress)) {
            return null;
        }

        return $remoteAddress;
    }

    /**
     * Adds the X-Originating-IP with the actual IP of the HTTP client to the
     * IMAP identifier sent before authentication.
     *
     * @param array $args
     *
     * @return array
     */
    public
    function on_storage_connect($args)
    {
        $ident = [];
        $ident_key = 'ident';
        $client_ip = $this->get_client_ip();

        if ($client_ip === null) {
            // Failed to determine client IP, skip.
            return null;
        }

        if (version_compare(version_parse(RCMAIL_VERSION), version_parse('1.5'), '>=')) {
            // Starting with RoundCube 1.5, a new preauth_ident field was added
            // as the other identifiers will be only provided after authentication.
            // https://github.com/roundcube/roundcubemail/issues/7860
            // https://github.com/roundcube/roundcubemail/blob/master/program/lib/Roundcube/rcube_imap_generic.php#L913
            $ident_key = 'preauth_ident';
        }

        // RoundCube IMAP by default puts some server and version information
        // fields as the IMAP identifier. This options array is merged with
        // the documented hook arguments and should be retained.
        // https://github.com/roundcube/roundcubemail/blob/master/program/lib/Roundcube/rcube_imap.php#L118
        if (isset($args[$ident_key]) && is_array($args[$ident_key])) {
            $ident = $args[$ident_key];
        }

        // Add the actual IP of the client.
        // https://www.dovecot.org/list/dovecot/2013-July/091271.html
        // https://doc.dovecot.org/settings/core/#login-trusted-networks
        $ident['x-originating-ip'] = $client_ip;

        // Returned data is merged with arguments and provided back to the
        // IMAP connect method.
        return [ $ident_key => $ident ];
    }

}