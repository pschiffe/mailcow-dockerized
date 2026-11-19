<?php

declare(strict_types=1);

namespace Mailcow\Tests\Auth;

use PDO;
use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../data/web/inc/functions.roundcube.inc.php';

#[BackupGlobals(true)]
class RoundcubeMailboxPolicyTest extends TestCase
{
    public static function mailboxes(): array
    {
        return [
            'eligible' => [[], 1, true, false],
            'password update required' => [['attributes' => ['force_pw_update' => '1']], 1, true, true],
            'external authentication does not set local password pending' => [
                ['authsource' => 'ldap', 'attributes' => ['force_pw_update' => '1']], 1, true, false,
            ],
            'missing mailbox' => [null, 1, false, false],
            'inactive mailbox' => [['active' => 0], 1, false, false],
            'inactive domain' => [[], 0, false, false],
            'resource mailbox' => [['kind' => 'location'], 1, false, false],
            'shared mailbox cannot enter password recovery' => [
                ['attributes' => ['shared' => '1', 'force_pw_update' => '1']], 1, false, false,
            ],
        ];
    }

    #[DataProvider('mailboxes')]
    public function testMailboxPolicy(?array $mailbox, int $domainActive, bool $eligible, bool $passwordPending): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->sqliteCreateFunction('regexp', fn ($pattern, $value) => preg_match('/' . $pattern . '/', $value) === 1, 2);
        $pdo->exec('CREATE TABLE domain (domain TEXT PRIMARY KEY, active INTEGER)');
        $pdo->exec('CREATE TABLE mailbox (username TEXT PRIMARY KEY, domain TEXT, active INTEGER, kind TEXT, authsource TEXT, attributes TEXT)');
        $pdo->prepare('INSERT INTO domain VALUES (?, ?)')->execute(['example.org', $domainActive]);
        if ($mailbox !== null) {
            $pdo->prepare('INSERT INTO mailbox VALUES (?, ?, ?, ?, ?, ?)')->execute([
                'user@example.org', 'example.org', $mailbox['active'] ?? 1, $mailbox['kind'] ?? '',
                $mailbox['authsource'] ?? 'mailcow', json_encode($mailbox['attributes'] ?? []),
            ]);
        }
        $_SESSION = $eligible ? [] : ['roundcube-sso-return' => true];

        $this->assertSame($eligible, \roundcube_sso_apply_mailbox_policy($pdo, 'user@example.org'));
        $this->assertSame($passwordPending ? ['pending_pw_update' => true, 'roundcube-sso-return' => true] : [], $_SESSION);
    }
}
