<?php
$config['password_driver'] = 'mailcow';
$config['password_confirm_current'] = true;
$config['password_mailcow_api_host'] = 'https://' . getenv('MAILCOW_HOSTNAME');
$config['password_mailcow_api_token'] = getenv('API_KEY');
