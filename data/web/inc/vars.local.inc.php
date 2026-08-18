<?php

$MAILCOW_APPS = [
    [
        'name' => 'SOGo',
        'link' => '/SOGo/so'
    ],
];

if (getenv('ENABLE_ROUNDCUBE') === 'y') {
    $MAILCOW_APPS[] = [
        'name' => 'Roundcube',
        'link' => '/rc/'
    ];
}
