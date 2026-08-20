<?php
$config['smtp_user'] = '%u';
$config['smtp_pass'] = '%p';
$config['support_url'] = '';
$config['product_name'] = 'Roundcube Webmail';
$config['cipher_method'] = 'chacha20-poly1305';
$config['session_path'] = '/rc/';
$config['http_authentication_host'] = getenv('IPV4_NETWORK') . '.250';
$config['logout_url'] = '/roundcube-auth.php?action=logout';
$config['enable_installer'] = false;

$config['managesieve_host'] = getenv('IPV4_NETWORK') . '.250:4190';
$config['managesieve_vacation'] = 1;

$config['address_book_type'] = '';


$roundcube_nginx_ip = gethostbyname('nginx');
$config['dovecot_client_ip_trusted_proxies'] = filter_var($roundcube_nginx_ip, FILTER_VALIDATE_IP)
    ? [$roundcube_nginx_ip]
    : [];
$config['dovecot_client_ip_proxy_allow_private_client_ip'] = false;
unset($roundcube_nginx_ip);
