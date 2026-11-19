<?php

declare(strict_types=1);

namespace Mailcow\Tests\Auth;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use League\OAuth2\Client\Provider\GenericProvider;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

// The app-password tests replace verify_hash(); load the real core in isolated processes.
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class RoundcubeOidcSessionTest extends TestCase
{
    private const SETTINGS = ['authsource' => 'generic-oidc'];
    private const SESSION = [
        'mailcow_cc_role' => 'user',
        'mailcow_cc_username' => 'alice@example.org',
        'iam_token' => 'access-1',
        'iam_refresh_token' => 'refresh-1',
    ];

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../../data/web/inc/functions.inc.php';
        require_once __DIR__ . '/../../../data/web/inc/functions.roundcube.inc.php';
        ini_set('session.use_cookies', '0');
        session_cache_limiter('');
        session_start();
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    private function mailbox(string $source, ?string $linkedSubject): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE mailbox (username TEXT PRIMARY KEY, authsource TEXT)');
        $pdo->exec('CREATE TABLE identity_link (username TEXT PRIMARY KEY, provider TEXT, external_id TEXT)');
        $pdo->prepare("INSERT INTO mailbox VALUES ('alice@example.org', ?)")->execute([$source]);
        if ($linkedSubject !== null) {
            $pdo->prepare("INSERT INTO identity_link VALUES ('alice@example.org', 'generic-oidc', ?)")->execute([$linkedSubject]);
        }
        return $pdo;
    }

    public static function refreshCases(): array
    {
        return [
            'rotating refresh token' => ['generic-oidc', 'subject', 'ALICE@example.org', ['refresh_token' => 'refresh-2'], true, 2],
            'response omits refresh token' => ['generic-oidc', 'subject', 'alice@example.org', [], true, 2],
            'OIDC source changed' => ['keycloak', 'subject', 'alice@example.org', [], false, 0],
            'switched to password authentication' => ['mailcow', 'subject', 'alice@example.org', [], false, 0],
            'identity link changed' => ['generic-oidc', 'other-subject', 'alice@example.org', [], false, 2],
            'identity link removed' => ['generic-oidc', null, 'alice@example.org', [], false, 0],
            'identity email changed' => ['generic-oidc', 'subject', 'bob@example.org', [], false, 2],
            'provider revoked refresh token' => ['generic-oidc', 'subject', 'alice@example.org', null, false, 1],
        ];
    }

    private function provider(HandlerStack $handler): GenericProvider
    {
        return new GenericProvider([
            'clientId' => 'test-client', 'clientSecret' => 'test-secret', 'redirectUri' => 'https://mailcow.test/',
            'urlAuthorize' => 'https://idp.test/auth', 'urlAccessToken' => 'https://idp.test/token',
            'urlResourceOwnerDetails' => 'https://idp.test/userinfo', 'responseResourceOwnerId' => 'sub',
        ], ['httpClient' => new Client(['handler' => $handler])]);
    }

    #[DataProvider('refreshCases')]
    public function testRefreshRequiresCurrentSourceAndIdentityLink(
        string $source, ?string $linkedSubject, string $email, ?array $tokenFields, bool $allowed, int $requestCount
    ): void {
        $pdo = $this->mailbox($source, $linkedSubject);
        $responses = [
            $tokenFields === null
                ? new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant"}')
                : new Response(200, ['Content-Type' => 'application/json'], json_encode(['access_token' => 'access-2'] + $tokenFields)),
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['email' => $email, 'sub' => 'subject'])),
        ];
        $handler = HandlerStack::create(new MockHandler($responses));
        $history = [];
        $handler->push(Middleware::history($history));
        $GLOBALS['iam_provider'] = $this->provider($handler);
        $GLOBALS['iam_settings'] = self::SETTINGS;
        $_SESSION = self::SESSION;
        $id = session_id();

        $this->assertSame($allowed, \roundcube_sso_validate_oidc_session($pdo));
        if ($allowed) {
            $this->assertSame($id, session_id());
        }
        $this->assertSame($allowed ? array_replace(self::SESSION, [
            'iam_token' => 'access-2',
            'iam_refresh_token' => $tokenFields['refresh_token'] ?? 'refresh-1',
        ]) : [], $_SESSION);
        $this->assertSame($linkedSubject, $pdo->query('SELECT external_id FROM identity_link')->fetchColumn() ?: null);
        $this->assertCount($requestCount, $history);
        if ($requestCount > 0) {
            parse_str((string) $history[0]['request']->getBody(), $grant);
            $this->assertSame('refresh-1', $grant['refresh_token']);
        }
    }

    public static function outages(): array
    {
        return [
            'connection failure before refresh' => ['connect', false],
            'malformed refresh token' => ['malformed', false],
            'token service unavailable' => ['503', false],
            'userinfo unavailable after token rotation' => ['503', true],
            'missing identity after token rotation' => ['{}', true],
            'malformed email after token rotation' => ['{"email":"not an address","sub":"subject"}', true],
            'malformed subject after token rotation' => ['{"email":"alice@example.org","sub":[]}', true],
        ];
    }

    #[DataProvider('outages')]
    public function testOutageDeniesRequestAndSessionCanRecover(string $fault, bool $rotated): void
    {
        $json = ['Content-Type' => 'application/json'];
        $responses = $rotated ? [new Response(200, $json, '{"access_token":"new","refresh_token":"rotated"}')] : [];
        $responses[] = match ($fault) {
            'connect' => new \GuzzleHttp\Exception\ConnectException('unavailable', new \GuzzleHttp\Psr7\Request('POST', 'https://idp.test/token')),
            'malformed' => new Response(200, $json, '{"access_token":"new","refresh_token":["invalid"]}'),
            '503' => new Response(503, $json, '{"error":"temporarily_unavailable"}'),
            default => new Response(200, $json, $fault),
        };
        $responses[] = new Response(200, $json, '{"access_token":"recovered"}');
        $responses[] = new Response(200, $json, '{"email":"alice@example.org","sub":"subject"}');
        $handler = HandlerStack::create(new MockHandler($responses));
        $history = [];
        $handler->push(Middleware::history($history));
        $GLOBALS['iam_provider'] = $this->provider($handler);
        $GLOBALS['iam_settings'] = self::SETTINGS;
        $_SESSION = self::SESSION;
        $pdo = $this->mailbox('generic-oidc', 'subject');
        try {
            \roundcube_sso_validate_oidc_session($pdo);
            $this->fail('An unavailable verifier must not grant access or revoke the session');
        } catch (\RoundcubeOidcUnavailable $e) {
            $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        }
        $this->assertTrue(\roundcube_sso_validate_oidc_session($pdo));
        parse_str((string)$history[count($history) - 2]['request']->getBody(), $grant);
        $this->assertSame($rotated ? 'rotated' : 'refresh-1', $grant['refresh_token']);
        foreach ($history as $request) {
            $this->assertGreaterThan(0, $request['options']['connect_timeout']);
            $this->assertGreaterThan(0, $request['options']['timeout']);
            $this->assertLessThan(15, $request['options']['timeout']);
        }
    }

    public function testPasswordSessionDoesNotRequireOidc(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $_SESSION = ['mailcow_cc_role' => 'user', 'mailcow_cc_username' => 'alice@example.org'];
        $this->assertTrue(\roundcube_sso_validate_oidc_session($pdo));
    }

    public function testInteractiveRefreshWithoutReplacementRequiresLoginWithoutDestroyingSession(): void
    {
        // State persisted by mailcow after /user refreshes the access token
        // and getRefreshToken() returns null.
        $_SESSION = array_replace(self::SESSION, ['iam_token' => 'new-access', 'iam_refresh_token' => null]);
        $saved = $_SESSION;
        session_write_close();
        session_start();
        $GLOBALS['iam_settings'] = self::SETTINGS;
        $GLOBALS['iam_provider'] = $this->provider(HandlerStack::create(new MockHandler()));
        try {
            \roundcube_sso_validate_oidc_session($this->mailbox('generic-oidc', 'subject'));
            $this->fail('Roundcube must require reauthentication');
        } catch (\RoundcubeOidcLoginRequired $e) {
            $this->assertSame(PHP_SESSION_ACTIVE, session_status());
            session_write_close();
            session_start();
            $this->assertSame($saved, $_SESSION);
        }
    }

    public static function incompleteSessions(): array
    {
        return [
            'missing access token' => [['iam_token'], self::SETTINGS, true],
            'missing provider configuration' => [[], [], false],
            'incomplete provider configuration' => [[], self::SETTINGS, false],
            'configured provider changed' => [[], ['authsource' => 'keycloak'], true],
        ];
    }

    #[DataProvider('incompleteSessions')]
    public function testIncompleteOidcStateRevokesExistingSession(array $missing, array $settings, bool $providerAvailable): void
    {
        $_SESSION = array_diff_key(self::SESSION, array_flip($missing));
        $id = session_id();
        session_write_close();
        session_start();
        $GLOBALS['iam_settings'] = $settings;
        $GLOBALS['iam_provider'] = $providerAvailable ? $this->provider(HandlerStack::create(new MockHandler())) : null;

        $this->assertFalse(\roundcube_sso_validate_oidc_session($this->mailbox('generic-oidc', 'subject')));
        $this->assertSame([], $_SESSION);
        $this->assertSame(PHP_SESSION_NONE, session_status());
        session_id($id);
        session_start();
        $this->assertSame([], $_SESSION);
        session_destroy();
    }
}
