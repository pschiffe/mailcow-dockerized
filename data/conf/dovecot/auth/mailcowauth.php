<?php
ini_set('error_reporting', 0);
header('Content-Type: application/json');

$post = trim(file_get_contents('php://input'));
if ($post) {
  $post = json_decode($post, true);
}


$return = array("success" => false);
if(!isset($post['username']) || !isset($post['password']) || !isset($post['real_rip'])){
  error_log("MAILCOWAUTH: Bad Request");
  http_response_code(400); // Bad Request
  echo json_encode($return);
  exit();
}

require_once('../../../web/inc/vars.inc.php');
if (file_exists('../../../web/inc/vars.local.inc.php')) {
  include_once('../../../web/inc/vars.local.inc.php');
}
require_once '../../../web/inc/lib/vendor/autoload.php';


// Init Redis
$redis = new Redis();
try {
  if (!empty(getenv('REDIS_SLAVEOF_IP'))) {
    $redis->connect(getenv('REDIS_SLAVEOF_IP'), getenv('REDIS_SLAVEOF_PORT'));
  }
  else {
    $redis->connect('redis-mailcow', 6379);
  }
  $redis->auth(getenv("REDISPASS"));
}
catch (Exception $e) {
  error_log("MAILCOWAUTH: " . $e . PHP_EOL);
  http_response_code(500); // Internal Server Error
  echo json_encode($return);
  exit;
}

// Init database
$dsn = $database_type . ":unix_socket=" . $database_sock . ";dbname=" . $database_name;
$opt = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
try {
  $pdo = new PDO($dsn, $database_user, $database_pass, $opt);
}
catch (PDOException $e) {
  error_log("MAILCOWAUTH: " . $e . PHP_EOL);
  http_response_code(500); // Internal Server Error
  echo json_encode($return);
  exit;
}

// Load core functions first
require_once 'functions.inc.php';
require_once 'functions.auth.inc.php';
require_once 'sessions.inc.php';
require_once 'functions.mailbox.inc.php';
require_once 'functions.ratelimit.inc.php';
require_once 'functions.acl.inc.php';


$trustedWebmailServices = [
  getenv('IPV4_NETWORK') . '.248' => 'SOGO',
  getenv('IPV4_NETWORK') . '.251' => 'ROUNDCUBE',
];
$webmailService = $trustedWebmailServices[$post['real_rip']] ?? null;
$isSOGoRequest = $post['real_rip'] == getenv('IPV4_NETWORK') . '.248';
// real_rip is the address that connected to Dovecot (for proxied connections the proxy), client_ip the client (for logs)
$client_ip = filter_var($post['client_ip'] ?? '', FILTER_VALIDATE_IP) ? $post['client_ip'] : $post['real_rip'];
$result = false;
// set when the password is right, but the account does not allow this login
$denied = false;
if ($webmailService !== null) {
  // Trusted webmail clients authenticate with the rotating internal SSO password.
  $sogo_sso_pass = file_get_contents("/etc/sogo-sso/sogo-sso.pass");
  if (!empty($sogo_sso_pass) && $sogo_sso_pass === $post['password']){
    error_log('MAILCOWAUTH: ' . $webmailService . ' SSO auth for user ' . $post['username']);
    set_sasl_log($post['username'], $client_ip, $webmailService);
    $result = true;
  }
}
if ($result === false){
  // Trusted webmail requests that miss SSO may use app passwords, but not regular protocol access.
  if ($webmailService !== null) {
    $service = $webmailService;
    $post['service'] = 'NONE';
  } else {
    $service = $post['service'];
  }

  $result = apppass_login($post['username'], $post['password'], array(
    'service' => $post['service'],
    'is_internal' => true,
    'remote_addr' => $post['real_rip']
  ), $denied);
  if ($result) {
    error_log('MAILCOWAUTH: App auth for user ' . $post['username'] . " with service " . $service . " from IP " . $client_ip);
    set_sasl_log($post['username'], $client_ip, $service);
  }
}
if ($result === false){
  // Init Identity Provider
  $iam_provider = identity_provider('init');
  $iam_settings = identity_provider('get');
  try {
    $result = user_login($post['username'], $post['password'], array('is_internal' => true, 'service' => $post['service']), $denied);
  } catch (\Mailcow\Auth\IdpUnavailable $e) {
    // temporary failure for Dovecot, no failed login
    error_log("MAILCOWAUTH: Identity provider unavailable for user " . $post['username'] . " with service " . $post['service'] . " from IP " . $client_ip . ": " . $e->getMessage());
    http_response_code(503); // Service Unavailable
    echo json_encode($return);
    session_destroy();
    exit;
  }
  if ($result) {
    error_log('MAILCOWAUTH: User auth for user ' . $post['username'] . " with service " . $post['service'] . " from IP " . $client_ip);
    set_sasl_log($post['username'], $client_ip, $post['service']);
  }
}

if ($result) {
  http_response_code(200); // OK
  $return['success'] = true;
} elseif ($denied) {
  // Logged, but no failed login for netfilter: e.g. a client of a shared mailbox or without IMAP access
  error_log("MAILCOWAUTH: Login denied for user " . $post['username'] . " with service " . $service . " from IP " . $client_ip);
  http_response_code(401); // Unauthorized
} else {
  // Same line for the log and netfilter, which counts it for IMAP, POP3, Sieve and SMTP (Postfix asks Dovecot);
  // SOGo logins have their own rule
  $failed = "MAILCOWAUTH: Login failed for user " . $post['username'] . " with service " .
    preg_replace('/[^a-z0-9]/', '', strtolower((string)$service)) . " from IP " . $client_ip;
  error_log($failed);
  if (!$isSOGoRequest) {
    $redis->publish("F2B_CHANNEL", $failed);
  }
  http_response_code(401); // Unauthorized
}


echo json_encode($return);
session_destroy();
exit;
