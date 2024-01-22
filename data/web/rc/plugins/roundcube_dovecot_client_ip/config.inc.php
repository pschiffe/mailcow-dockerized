<?php

// Array of trusted reverse proxy IP addresses. If empty or not configured, no
// reverse proxy is taken into account and only the REMOTE_ADDR will be
// reported. IPs are compared to REMOTE_ADDR.
$config['dovecot_client_ip_trusted_proxies'] = [];
// Whether to allow private range IPs in reverse proxy forwarded client
// headers. Should only be enabled in private environments.
$config['dovecot_client_ip_proxy_allow_private_client_ip'] = false;