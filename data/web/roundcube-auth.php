<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/prerequisites.inc.php';

$action = $_GET['action'] ?? '';
if (!in_array($action, ['login', 'logout'], true)) {
  http_response_code(404);
  exit;
}

if ($action === 'logout') {
  unset($_SESSION['roundcube-sso-return']);
  clear_session();
  header('Location: /');
  exit;
}

$pending_fields = [
  'pending_tfa_setup',
  'pending_pw_update',
  'pending_mailcow_cc_username',
  'pending_mailcow_cc_role',
  'pending_tfa_methods',
];
$has_pending_state = false;
foreach ($pending_fields as $field) {
  if (!empty($_SESSION[$field])) {
    $has_pending_state = true;
    break;
  }
}

$role = $_SESSION['mailcow_cc_role'] ?? '';
$username = $_SESSION['mailcow_cc_username'] ?? '';
$allowed_users = $_SESSION['sogo-sso-user-allowed'] ?? null;
$is_mailbox_owner = (
  $role === 'user' &&
  empty($_SESSION['dual-login']) &&
  filter_var($username, FILTER_VALIDATE_EMAIL) !== false &&
  is_array($allowed_users) &&
  in_array($username, $allowed_users, true)
);

if ($is_mailbox_owner && !$has_pending_state) {
  $sso_password = @file_get_contents('/etc/sogo-sso/sogo-sso.pass');
  if ($sso_password === false || $sso_password === '') {
    http_response_code(503);
    exit;
  }
  unset($_SESSION['roundcube-sso-return']);
  header('Location: /rc/');
  exit;
}

if ($role === '' || ($is_mailbox_owner && $has_pending_state)) {
  $_SESSION['roundcube-sso-return'] = true;
} else {
  unset($_SESSION['roundcube-sso-return']);
}

header('Location: /');
exit;
