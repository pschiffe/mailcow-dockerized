<?php

require_once dirname(__DIR__) . '/http_authentication/http_authentication.php';

/**
 * Bind Roundcube's HTTP-authenticated session to the current mailcow user.
 */
class mailcow_http_authentication extends http_authentication
{
    public function startup($args)
    {
        if (!empty($_SERVER['PHP_AUTH_USER']) && !empty($_SESSION['user_id'])) {
            $rcmail = rcmail::get_instance();
            $session_user = $rcmail->user->get_username();

            if ($session_user !== $_SERVER['PHP_AUTH_USER']) {
                $rcmail->kill_session();
            }
        }

        return parent::startup($args);
    }

    public function authenticate($args)
    {
        if (!empty($_SERVER['PHP_AUTH_USER'])) {
            // Never allow submitted Roundcube credentials to override the mailcow identity.
            $args['user'] = '';
            $args['pass'] = '';
        }

        return parent::authenticate($args);
    }
}
