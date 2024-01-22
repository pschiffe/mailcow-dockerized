<?php
$config['db_dsnw'] = 'mysql://roundcube:' . getenv('DBROUNDCUBE') . '@mysql/roundcubemail';
$config['imap_host'] = 'dovecot:143';
$config['smtp_host'] = 'postfix:588';
$config['smtp_user'] = '%u';
$config['smtp_pass'] = '%p';
$config['support_url'] = '';
$config['product_name'] = 'Roundcube Webmail';
$config['cipher_method'] = 'chacha20-poly1305';
$config['des_key'] = getenv('ROUNDCUBE_DES_KEY');
$config['plugins'] = [
	'acl',
	'archive',
	'carddav',
	'managesieve',
	'markasjunk',
	'password',
	'roundcube_dovecot_client_ip',
	'zipdownload',
];
$config['mime_types'] = '/web/rc/config/mime.types';
$config['enable_installer'] = false;

$config['managesieve_host'] = 'dovecot:4190';
// Enables separate management interface for vacation responses (out-of-office)
// 0 - no separate section (default); 1 - add Vacation section; 2 - add Vacation section, but hide Filters section
$config['managesieve_vacation'] = 1;

$config['address_book_type'] = '';
