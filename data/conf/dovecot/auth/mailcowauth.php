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
$result = false;
if ($webmailService !== null) {
  // Trusted webmail clients authenticate with the rotating internal SSO password.
  $sogo_sso_pass = file_get_contents("/etc/sogo-sso/sogo-sso.pass");
  if (!empty($sogo_sso_pass) && $sogo_sso_pass === $post['password']){
    error_log('MAILCOWAUTH: ' . $webmailService . ' SSO auth for user ' . $post['username']);
    set_sasl_log($post['username'], $post['real_rip'], $webmailService);
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
  ));
  if ($result) {
    error_log('MAILCOWAUTH: App auth for user ' . $post['username'] . " with service " . $service . " from IP " . $post['real_rip']);
    set_sasl_log($post['username'], $post['real_rip'], $service);
  }
}
if ($result === false){
  // Init Identity Provider
  $iam_provider = identity_provider('init');
  $iam_settings = identity_provider('get');
  $result = user_login($post['username'], $post['password'], array('is_internal' => true, 'service' => $post['service']));
  if ($result) {
    error_log('MAILCOWAUTH: User auth for user ' . $post['username'] . " with service " . $post['service'] . " from IP " . $post['real_rip']);
    set_sasl_log($post['username'], $post['real_rip'], $post['service']);
  }
}

if ($result) {
  http_response_code(200); // OK
  $return['success'] = true;
} else {
  error_log("MAILCOWAUTH: Login failed for user " . $post['username'] . " with service " . $post['service'] . " from IP " . $post['real_rip']);
  http_response_code(401); // Unauthorized
}


echo json_encode($return);
session_destroy();
exit;
