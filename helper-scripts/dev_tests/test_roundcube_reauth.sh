#!/usr/bin/env bash
# Requires PHP CLI with pdo_sqlite and curl. No running mailcow or IdP needed.
set -euo pipefail
web=$(cd "$(dirname "$0")/../../data/web" && pwd)
tmp=$(mktemp -d)
trap 'if [[ -n ${server:-} ]]; then kill "$server" 2>/dev/null || :; fi; rm -rf "$tmp"' EXIT
mkdir "$tmp/inc" "$tmp/sessions"
ln -s "$web/index.php" "$web/roundcube-auth.php" "$tmp/"
ln -s "$web/inc/"{functions.roundcube.inc.php,triggers.user.inc.php} "$tmp/inc/"
cat > "$tmp/inc/prerequisites.inc.php" <<'PHP'
<?php
require getenv('TEST_WEB') . '/inc/lib/vendor/autoload.php';
require getenv('TEST_WEB') . '/inc/functions.inc.php';
// The fixture mailbox uses the mailcow user page, not SOGo, as its landing page.
function mailbox($action, $object, $username) {
  if ([$action, $object, $username] !== ['get', 'mailbox_details', 'alice@example.org']) {
    throw new LogicException('Unexpected mailbox lookup');
  }
  return ['attributes' => ['sogo_access' => 0]];
}
session_start();
$pdo = new PDO('sqlite::memory:');
$pdo->sqliteCreateFunction('regexp', fn($pattern, $value) => preg_match("/$pattern/", $value), 2);
$pdo->exec("CREATE TABLE domain(domain, active); INSERT INTO domain VALUES('example.org','1');
  CREATE TABLE mailbox(username,domain,active,authsource,kind,attributes);
  INSERT INTO mailbox VALUES('alice@example.org','example.org','1','generic-oidc','','{}');
  CREATE TABLE identity_link(username,provider,external_id,idp_disabled,missing_since);
  INSERT INTO identity_link VALUES('alice@example.org','generic-oidc','subject',0,NULL)");
$iam_settings = ['authsource' => 'generic-oidc'];
$iam_provider = new League\OAuth2\Client\Provider\GenericProvider([
  'clientId'=>'test', 'clientSecret'=>'test', 'redirectUri'=>'/', 'urlAuthorize'=>'https://idp.test/auth',
  'urlAccessToken'=>'https://idp.test/token', 'urlResourceOwnerDetails'=>'https://idp.test/userinfo',
]);
$json = ['Content-Type'=>'application/json'];
$iam_provider->setHttpClient(new GuzzleHttp\Client(['handler'=>new GuzzleHttp\Handler\MockHandler([
  new GuzzleHttp\Psr7\Response(200, $json, file_exists(__DIR__.'/access-only')
    ? '{"access_token":"new"}' : '{"access_token":"new","refresh_token":"replacement"}'),
  new GuzzleHttp\Psr7\Response(200, $json, '{"email":"alice@example.org","sub":"subject"}'),
])]));
PHP
cat > "$tmp/seed.php" <<'PHP'
<?php
session_start();
$_SESSION = ['mailcow_cc_role'=>'user','mailcow_cc_username'=>'alice@example.org',
  'sogo-sso-user-allowed'=>['alice@example.org'],'iam_token'=>'old','iam_refresh_token'=>null];
PHP
cat > "$tmp/state.php" <<'PHP'
<?php
session_start(['read_and_close' => true]);
echo json_encode([$_SESSION['mailcow_cc_role'] ?? null, $_SESSION['mailcow_cc_username'] ?? null,
  $_SESSION['iam_token'] ?? null, $_SESSION['iam_refresh_token'] ?? null, $_SESSION['roundcube-sso-return'] ?? false]);
PHP
TEST_WEB="$web" php -d session.save_path="$tmp/sessions" -d display_errors=0 -S 127.0.0.1:0 -t "$tmp" >"$tmp/log" 2>&1 & server=$!
for ((i=0; i<100; i++)); do
  url=$(grep -oE 'http://127\.0\.0\.1:[0-9]+' "$tmp/log" || :)
  [[ -z $url ]] || break
  sleep .05
done
request() { curl -sS -b "$tmp/cookies" -c "$tmp/cookies" "$url$1" "${@:2}"; }
redirect() { request "$1" -o /dev/null -w '%{redirect_url}'; }
exercise_reauth() {
request /seed.php --fail -o /dev/null
[[ $(request '/roundcube-auth.php?action=login' -o "$tmp/page" -w '%{http_code}') == 403 ]]
link=$(grep -oE 'href="[^"]+">Sign in again' "$tmp/page" | cut -d '"' -f 2)
[[ $(request /state.php) == '["user","alice@example.org","old",null,false]' ]]
start=$(redirect "$link"); [[ $start == "$url/?iam_sso=1" ]]
idp=$(redirect "${start#"$url"}"); [[ $idp == https://idp.test/auth\?* ]]
# A second tab follows the normal mailbox route while the first is at the IdP.
[[ $(redirect /) == "$url/user" ]]
[[ $(request '/roundcube-auth.php?action=login' -o "$tmp/page" -w '%{http_code}') == 403 ]]
grep -Fq "href=\"$link\"" "$tmp/page"
state=$(php -r 'parse_str(parse_url($argv[1], PHP_URL_QUERY), $q); echo $q["state"];' "$idp")
[[ -n $state && $(redirect "/?code=test&state=$state") == "$url/rc/" ]]
if [[ -e $tmp/inc/access-only ]]; then
  [[ $(request '/roundcube-auth.php?action=login' -o "$tmp/page" -w '%{http_code}') == 403 ]]
  grep -q 'enable refresh-token issuance' "$tmp/page"
  ! grep -q 'action=reauth' "$tmp/page" || exit 1
  [[ $(request "$link" -o /dev/null -w '%{http_code}') == 403 ]]
  [[ $(request /state.php) == '["user","alice@example.org","new",null,false]' ]]
  echo 'Access-only provider: Roundcube denied with configuration guidance'
else
  [[ $(request /state.php) == '["user","alice@example.org","new","replacement",false]' ]]
fi
}
exercise_reauth
rm "$tmp/cookies"
touch "$tmp/inc/access-only"
exercise_reauth
echo 'Roundcube recovery: 403 choice -> provider login -> callback -> /rc/'
