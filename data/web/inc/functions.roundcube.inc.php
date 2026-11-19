<?php

final class RoundcubeOidcUnavailable extends RuntimeException {}
final class RoundcubeOidcLoginRequired extends RuntimeException {}

// Password-based sessions, including IdP password logins, do not carry OIDC state.
function roundcube_sso_validate_oidc_session(PDO $pdo): bool {
  if (!array_key_exists('iam_token', $_SESSION) && !array_key_exists('iam_refresh_token', $_SESSION)) {
    return true;
  }

  require_once __DIR__ . '/functions.inc.php';
  require_once __DIR__ . '/lib/vendor/autoload.php';
  if (isset($GLOBALS['iam_settings'])) {
    $settings = $GLOBALS['iam_settings'];
    $provider = $GLOBALS['iam_provider'] ?? null;
  } else {
    // The nginx verifier does not run the interactive UI bootstrap.
    $settings = identity_provider('get', null, ['hide_sensitive' => false]);
    $provider = in_array($settings['authsource'] ?? null, ['keycloak', 'generic-oidc'], true)
      ? identity_provider('init')
      : null;
  }
  if (roundcube_sso_refresh_oidc($pdo, $provider, $settings['authsource'] ?? null)) {
    unset($_SESSION['roundcube-reauth']);
    return true;
  }

  // auth_request does not relay Set-Cookie. Revoke the existing server-side ID.
  $_SESSION = [];
  session_destroy();
  return false;
}

// The interactive mailcow refresh rotates the PHP session ID. An nginx auth
// subrequest cannot deliver that cookie, so update only the provider tokens.
function roundcube_sso_refresh_oidc(PDO $pdo, $provider, ?string $source): bool {
  $username = $_SESSION['mailcow_cc_username'];
  $refresh = $_SESSION['iam_refresh_token'] ?? null;
  if (!in_array($source, ['keycloak', 'generic-oidc'], true) ||
    !$provider instanceof \League\OAuth2\Client\Provider\AbstractProvider ||
    !is_string($_SESSION['iam_token'] ?? null) || $_SESSION['iam_token'] === '') {
    return false;
  }

  // Read only: an existing session must never recreate a removed identity link.
  $stmt = $pdo->prepare('SELECT l.external_id FROM identity_link l
    JOIN mailbox m ON m.username = l.username AND m.authsource = l.provider
    WHERE m.username = :username AND l.provider = :provider');
  $stmt->execute(['username' => $username, 'provider' => $source]);
  $subject = $stmt->fetchColumn();
  if (!is_string($subject) || $subject === '') {
    return false;
  }
  // Interactive mailcow refresh may store null when the IdP does not rotate
  // its refresh token. We cannot verify this session, but it is not revoked.
  if (!is_string($refresh) || $refresh === '') {
    throw new RoundcubeOidcLoginRequired();
  }
  // The provider normally belongs to the interactive UI. Keep the deadlines
  // local, including its existing TLS and proxy settings. No redirect chains.
  $provider = clone $provider;
  $config = $provider->getHttpClient()->getConfig();
  $stack = clone $config['handler'];
  $stack->push(\GuzzleHttp\Middleware::mapResponse(static function ($response) {
    // The OAuth library otherwise parses HTTP errors as ordinary OAuth JSON.
    $status = $response->getStatusCode();
    if ($status === 400) {
      $error = json_decode((string)$response->getBody(), true);
      if (($error['error'] ?? null) === 'invalid_grant') {
        throw new \League\OAuth2\Client\Provider\Exception\IdentityProviderException('invalid_grant', 400, $error);
      }
    }
    if ($status >= 300) {
      throw new RoundcubeOidcUnavailable();
    }
    return $response;
  }));
  $provider->setHttpClient(new \GuzzleHttp\Client([
    'handler' => $stack, 'connect_timeout' => 2, 'timeout' => 5, 'allow_redirects' => false,
  ] + $config));
  try {
    $token = $provider->getAccessToken('refresh_token', ['refresh_token' => $refresh]);
    $access = $token->getToken();
    $next = $token->getRefreshToken() ?? $refresh;
    if (!is_string($access) || $access === '' || !is_string($next) || $next === '') {
      throw new RoundcubeOidcUnavailable();
    }
    // A successful exchange can invalidate the old token immediately. Keep
    // the replacement even if userinfo is unavailable; do not grant access yet.
    $_SESSION['iam_token'] = $access;
    $_SESSION['iam_refresh_token'] = $next;
    $info = $provider->getResourceOwner($token)->toArray();
  } catch (\League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) {
    if (($e->getResponseBody()['error'] ?? null) === 'invalid_grant') {
      return false;
    }
    throw new RoundcubeOidcUnavailable();
  } catch (Throwable $e) {
    throw new RoundcubeOidcUnavailable();
  }
  if (!is_string($info['email'] ?? null) || !filter_var($info['email'], FILTER_VALIDATE_EMAIL) ||
    !is_string($info['sub'] ?? null) || trim($info['sub']) === '') {
    throw new RoundcubeOidcUnavailable();
  }
  return strcasecmp($info['email'], $username) === 0 && $info['sub'] === $subject;
}

// A mailbox can be eligible but still require a password change before SSO.
function roundcube_sso_apply_mailbox_policy(PDO $pdo, string $username): bool {
  $stmt = $pdo->prepare("SELECT `mailbox`.`attributes`, `mailbox`.`authsource`
    FROM `mailbox`
    INNER JOIN `domain` ON `mailbox`.`domain` = `domain`.`domain`
    WHERE `mailbox`.`username` = :username
      AND `mailbox`.`active` = '1'
      AND `domain`.`active` = '1'
      AND `mailbox`.`kind` NOT REGEXP 'location|thing|group'");
  $stmt->execute([':username' => $username]);
  $mailbox = $stmt->fetch(PDO::FETCH_ASSOC);
  $attributes = $mailbox ? json_decode($mailbox['attributes'], true) : null;
  if ($mailbox === false || ($attributes['shared'] ?? '0') == '1') {
    unset($_SESSION['roundcube-sso-return']);
    return false;
  }
  if ($mailbox['authsource'] === 'mailcow' && intval($attributes['force_pw_update'] ?? 0) === 1) {
    $_SESSION['pending_pw_update'] = true;
    $_SESSION['roundcube-sso-return'] = true;
  }
  return true;
}
