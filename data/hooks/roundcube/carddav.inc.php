<?php
$prefs['_GLOBAL']['pwstore_scheme'] = 'des_key';

$prefs['SOGo'] = [
    'accountname'   => 'SOGo (mailcow app password required)',
    'username'      => '%u',
    'password'      => '',
    'discovery_url' => 'https://' . getenv('MAILCOW_HOSTNAME') . '/SOGo/dav/',
    'name'          => '%N',
    'use_categories' => true,
    'fixed'         => ['accountname', 'username', 'discovery_url'],
];
