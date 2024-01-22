<?php

/*
 * RCMCardDAV - CardDAV plugin for Roundcube webmail
 *
 * Copyright (C) 2011-2022 Benjamin Schieder <rcmcarddav@wegwerf.anderdonau.de>,
 *                         Michael Stilkerich <ms@mike2k.de>
 *
 * This file is part of RCMCardDAV.
 *
 * RCMCardDAV is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * RCMCardDAV is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with RCMCardDAV. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace MStilkerich\RCMCardDAV;

use Exception;
use Psr\Log\{LoggerInterface,LogLevel};
use MStilkerich\CardDavClient\{Account,WebDavResource};
use MStilkerich\CardDavClient\Services\{Discovery,Sync};
use MStilkerich\RCMCardDAV\Db\{Database, AbstractDatabase};
use MStilkerich\RCMCardDAV\Frontend\{RcmInterface, AdminSettings, AddressbookManager, Utils};
use rcube;
use rcube_cache;

/**
 * This class is intended as a central point to inject dependencies to infrastructure classes.
 *
 * This allows replacing dependencies in unit tests with mock objects.
 *
 * @psalm-import-type AccountSettings from AddressbookManager
 */
class Config
{
    /** @var ?Config The single instance of this class - can be exchanged by tests */
    public static $inst;

    /** @var ?RcmInterface Adapter to roundcube */
    protected $rc;

    /**
     * The admin settings configured in the config.inc.php file.
     * @var AdminSettings
     */
    protected $admPrefs;

    /** @var LoggerInterface */
    protected $logger;

    /** @var LoggerInterface */
    protected $httpLogger;

    /** @var AbstractDatabase */
    protected $db;

    /** @var ?rcube_cache */
    protected $cache;

    public static function inst(): Config
    {
        if (!isset(self::$inst)) {
            self::$inst = new Config();
        }

        return self::$inst;
    }

    public function __construct()
    {
        $this->logger = new RoundcubeLogger("carddav", LogLevel::ERROR);
        $this->httpLogger = new RoundcubeLogger("carddav_http", LogLevel::ERROR);
        $this->admPrefs = new AdminSettings(__DIR__ . '/../config.inc.php', $this->logger, $this->httpLogger);

        $rcube = rcube::get_instance();
        $this->db = new Database($this->logger, $rcube->db);
    }

    public function db(): AbstractDatabase
    {
        return $this->db;
    }

    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    public function httpLogger(): LoggerInterface
    {
        return $this->httpLogger;
    }

    public function rc(?RcmInterface $rc = null): RcmInterface
    {
        if (!isset($this->rc)) {
            if (isset($rc)) {
                $this->rc = $rc;
            } else {
                throw new Exception("Roundcube adapter not set");
            }
        }

        return $this->rc;
    }

    public function admPrefs(): AdminSettings
    {
        return $this->admPrefs;
    }

    /**
     * Returns a handle to the roundcube cache for the user.
     *
     * Note: this must be called when the user is already logged on, specifically it must not be called
     * during the constructor of the plugin.
     */
    public function cache(): rcube_cache
    {
        if (!isset($this->cache)) {
            // TODO make TTL and cache type configurable
            $rcube = rcube::get_instance();
            $this->cache = $rcube->get_cache("carddav", "db", "1w");
        }

        if (!isset($this->cache)) {
            throw new Exception("Attempt to request cache where not available yet");
        }

        return $this->cache;
    }

    public function makeDiscoveryService(): Discovery
    {
        return new Discovery();
    }

    public function makeSyncService(): Sync
    {
        return new Sync();
    }

    public function makeWebDavResource(string $uri, Account $account): WebDavResource
    {
        return WebDavResource::createInstance($uri, $account);
    }

    /**
     * Creates an Account object from the credentials in rcmcarddav.
     *
     * Particularly, this takes care of setting up the credentials information properly.
     *
     * @param AccountSettings $accountCfg
     */
    public static function makeAccount(array $accountCfg): Account
    {
        $password = Utils::replacePlaceholdersPassword($accountCfg['password'] ?? '');

        if ($password == "%b") {
            if (
                isset($_SESSION['oauth_token'])
                && is_array($_SESSION['oauth_token'])
                && isset($_SESSION['oauth_token']['access_token'])
                && is_string($_SESSION['oauth_token']['access_token'])
            ) {
                $httpOptions = [ 'bearertoken' => $_SESSION['oauth_token']['access_token'] ];
            } else {
                throw new Exception("OAUTH2 bearer authentication requested, but no token available in roundcube");
            }
        } else {
            $username = Utils::replacePlaceholdersUsername($accountCfg['username'] ?? '');
            $httpOptions = [ 'username' => $username, 'password' => $password ];
        }

        $httpOptions['preemptive_basic_auth'] = (bool) ($accountCfg['preemptive_basic_auth'] ?? false);
        $httpOptions['verify'] = !((bool) ($accountCfg['ssl_noverify'] ?? false));

        $discUrl  = Utils::replacePlaceholdersUrl($accountCfg['discovery_url'] ?? '');
        return new Account($discUrl, $httpOptions);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
