<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Service;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Data\UserData;
use Tarot\Data\UserTokenData;
use Tarot\Repository\UserRepository;
use Tarot\Repository\UserTokenRepository;
use Tarot\Service\AuthService;
use Tarot\Service\Mailer;
use Tarot\Structure\User;

#[CoversClass(AuthService::class)]
#[CoversClass(UserData::class)]
#[CoversClass(UserTokenData::class)]
#[CoversClass(UserRepository::class)]
#[CoversClass(UserTokenRepository::class)]
final class AuthServiceTest extends TestCase
{
    private PDO $pdo;
    private AuthService $auth;
    private UserRepository $userRepo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Mirror db/migrate_users.php + migrate_google_oauth.php +
        // migrate_passkeys.php. UserData::hydrate() always probes user_passkeys
        // and authenticate() reads users.password_login_disabled, so the test
        // schema must include the OAuth/passkey columns and table.
        $this->pdo->exec(
            'CREATE TABLE users (
                user_id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL COLLATE NOCASE,
                password_hash TEXT NOT NULL,
                display_name TEXT NOT NULL COLLATE NOCASE,
                is_active INTEGER NOT NULL DEFAULT 0,
                is_admin INTEGER NOT NULL DEFAULT 0,
                registered_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_login_at TEXT DEFAULT NULL,
                google_id TEXT DEFAULT NULL,
                password_login_disabled INTEGER NOT NULL DEFAULT 0
            )'
        );
        $this->pdo->exec('CREATE UNIQUE INDEX idx_users_email ON users (email COLLATE NOCASE)');
        $this->pdo->exec('CREATE UNIQUE INDEX idx_users_display_name ON users (display_name COLLATE NOCASE)');
        $this->pdo->exec('CREATE UNIQUE INDEX idx_users_google_id ON users (google_id)');
        $this->pdo->exec(
            'CREATE TABLE user_tokens (
                token_id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                token_hash TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                used_at TEXT DEFAULT NULL
            )'
        );
        $this->pdo->exec(
            "CREATE TABLE user_passkeys (
                passkey_id     INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id        INTEGER NOT NULL,
                credential_id  TEXT    NOT NULL,
                public_key_pem TEXT    NOT NULL,
                name           TEXT    NOT NULL DEFAULT 'My Passkey',
                sign_count     INTEGER NOT NULL DEFAULT 0,
                created_at     TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_used_at   TEXT    DEFAULT NULL,
                FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
            )"
        );

        $this->userRepo = new UserRepository(new UserData($this->pdo));
        $tokens = new UserTokenRepository(new UserTokenData($this->pdo));
        $this->auth = new AuthService($this->userRepo, $tokens, new Mailer());
    }

    private function tokenFromLink(string $link): string
    {
        parse_str((string)parse_url($link, PHP_URL_QUERY), $q);
        return (string)($q['token'] ?? '');
    }

    public function testRegisterCreatesInactiveUserWithActivationLink(): void
    {
        $result = $this->auth->register('Person@Example.com', 'Seeker', 'correct horse battery staple');

        $this->assertTrue($result['ok']);
        $this->assertInstanceOf(User::class, $result['user']);
        $this->assertFalse($result['user']->is_active);
        $this->assertFalse($result['user']->is_admin);
        // Email is normalised to lowercase.
        $this->assertSame('person@example.com', $result['user']->email);
        $this->assertStringContainsString('/activate?token=', $result['activation_link']);
    }

    public function testDuplicateEmailAndDisplayNameAreRejectedCaseInsensitively(): void
    {
        $this->auth->register('dupe@example.com', 'Mystic', 'correct horse battery staple');

        $dupeEmail = $this->auth->register('DUPE@example.com', 'Different', 'correct horse battery staple');
        $this->assertFalse($dupeEmail['ok']);

        $dupeName = $this->auth->register('other@example.com', 'mystic', 'correct horse battery staple');
        $this->assertFalse($dupeName['ok']);
    }

    public function testWeakPasswordIsRejected(): void
    {
        $result = $this->auth->register('weak@example.com', 'Weakling', 'short');
        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testLoginFailsBeforeActivationThenSucceedsAfter(): void
    {
        $password = 'correct horse battery staple';
        $reg = $this->auth->register('flow@example.com', 'Flowy', $password);

        // Not activated yet.
        $this->assertSame('inactive', $this->auth->authenticate('flow@example.com', $password)['status']);

        // Activate via the emailed token.
        $this->assertTrue($this->auth->activate($this->tokenFromLink($reg['activation_link'])));

        // Now login works (case-insensitive email), wrong password fails.
        $ok = $this->auth->authenticate('FLOW@example.com', $password);
        $this->assertSame('ok', $ok['status']);
        $this->assertInstanceOf(User::class, $ok['user']);
        $this->assertTrue($ok['user']->is_active);

        $this->assertSame('invalid', $this->auth->authenticate('flow@example.com', 'wrong password here')['status']);
    }

    public function testLoginIsBlockedWhenPasswordLoginIsDisabled(): void
    {
        $password = 'correct horse battery staple';
        $reg = $this->auth->register('nopw@example.com', 'NoPass', $password);
        $this->auth->activate($this->tokenFromLink($reg['activation_link']));

        // Simulate the user disabling password login (passkey-only), as the
        // account settings / passkey flow does.
        $this->pdo->exec(
            'UPDATE users SET password_login_disabled = 1 WHERE user_id = '
            . (int)$reg['user']->user_id
        );

        // Correct credentials are still refused — login must go via passkey/Google.
        $this->assertSame('password_disabled', $this->auth->authenticate('nopw@example.com', $password)['status']);
        // A wrong password is reported as invalid, not as disabled (no enumeration of the toggle).
        $this->assertSame('invalid', $this->auth->authenticate('nopw@example.com', 'wrong password here')['status']);
    }

    public function testActivationTokenIsSingleUse(): void
    {
        $reg = $this->auth->register('once@example.com', 'Once', 'correct horse battery staple');
        $token = $this->tokenFromLink($reg['activation_link']);

        $this->assertTrue($this->auth->activate($token));
        // Re-using the same token must fail.
        $this->assertFalse($this->auth->activate($token));
    }

    public function testUnknownTokenIsRejected(): void
    {
        $this->assertFalse($this->auth->activate('deadbeef'));
    }

    public function testResendActivationReissuesAWorkingTokenForInactiveUser(): void
    {
        $reg = $this->auth->register('resend@example.com', 'Resender', 'correct horse battery staple');
        $userId = $reg['user']->user_id;

        $resent = $this->auth->resendActivation($userId);
        $this->assertTrue($resent['ok']);
        $this->assertArrayHasKey('activation_link', $resent);

        // The original token is invalidated; the freshly issued one activates.
        $this->assertFalse($this->auth->activate($this->tokenFromLink($reg['activation_link'])));
        $this->assertTrue($this->auth->activate($this->tokenFromLink($resent['activation_link'])));
    }

    public function testResendActivationFailsForActiveOrMissingUser(): void
    {
        $reg = $this->auth->register('active@example.com', 'Active', 'correct horse battery staple');
        $this->auth->activate($this->tokenFromLink($reg['activation_link']));

        $this->assertFalse($this->auth->resendActivation($reg['user']->user_id)['ok']);
        $this->assertFalse($this->auth->resendActivation(99999)['ok']);
    }

    public function testPasswordResetFlowChangesThePassword(): void
    {
        $old = 'correct horse battery staple';
        $new = 'a different long passphrase';
        $reg = $this->auth->register('reset@example.com', 'Resetter', $old);
        $this->auth->activate($this->tokenFromLink($reg['activation_link']));

        $req = $this->auth->requestPasswordReset('RESET@example.com'); // case-insensitive
        $this->assertTrue($req['ok']);
        $this->assertTrue($req['exists']);
        $this->assertStringContainsString('/reset-password?token=', $req['reset_link']);

        $token = $this->tokenFromLink($req['reset_link']);
        $this->assertSame('ok', $this->auth->resetPassword($token, $new)['status']);

        // New password works; old one no longer does.
        $this->assertSame('ok', $this->auth->authenticate('reset@example.com', $new)['status']);
        $this->assertSame('invalid', $this->auth->authenticate('reset@example.com', $old)['status']);
    }

    public function testResetTokenIsSingleUse(): void
    {
        $reg = $this->auth->register('once-reset@example.com', 'OnceReset', 'correct horse battery staple');
        $this->auth->activate($this->tokenFromLink($reg['activation_link']));
        $token = $this->tokenFromLink($this->auth->requestPasswordReset('once-reset@example.com')['reset_link']);

        $this->assertSame('ok', $this->auth->resetPassword($token, 'a different long passphrase')['status']);
        $this->assertSame('invalid', $this->auth->resetPassword($token, 'yet another passphrase')['status']);
    }

    public function testWeakNewPasswordIsRejectedWithoutConsumingTheToken(): void
    {
        $reg = $this->auth->register('weakreset@example.com', 'WeakReset', 'correct horse battery staple');
        $this->auth->activate($this->tokenFromLink($reg['activation_link']));
        $token = $this->tokenFromLink($this->auth->requestPasswordReset('weakreset@example.com')['reset_link']);

        // Weak password rejected...
        $this->assertSame('weak', $this->auth->resetPassword($token, 'short')['status']);
        // ...and the same token still works with a strong one.
        $this->assertSame('ok', $this->auth->resetPassword($token, 'a strong enough passphrase')['status']);
    }

    public function testRequestResetForUnknownEmailReportsNoExistenceButStillOk(): void
    {
        $res = $this->auth->requestPasswordReset('nobody@example.com');
        $this->assertTrue($res['ok']);
        $this->assertFalse($res['exists']);
    }

    public function testActivationTokenCannotBeUsedAsResetToken(): void
    {
        $reg = $this->auth->register('mix@example.com', 'Mixer', 'correct horse battery staple');
        // The activation token is not valid for a password reset.
        $this->assertSame('invalid', $this->auth->resetPassword($this->tokenFromLink($reg['activation_link']), 'a brand new passphrase')['status']);
    }

    public function testGetAllAndDelete(): void
    {
        $a = $this->auth->register('one@example.com', 'One', 'correct horse battery staple');
        $this->auth->register('two@example.com', 'Two', 'correct horse battery staple');

        $this->assertCount(2, $this->userRepo->getAll());

        $this->assertTrue($this->userRepo->delete($a['user']->user_id));
        $this->assertCount(1, $this->userRepo->getAll());
        $this->assertNull($this->userRepo->findById($a['user']->user_id));
    }

    public function testSetAdminFlag(): void
    {
        $reg = $this->auth->register('boss@example.com', 'Boss', 'correct horse battery staple');
        $userId = $reg['user']->user_id;

        $this->assertFalse($this->userRepo->findById($userId)->is_admin);

        $this->userRepo->setAdmin($userId, true);
        $this->assertTrue($this->userRepo->findById($userId)->is_admin);

        $this->userRepo->setAdmin($userId, false);
        $this->assertFalse($this->userRepo->findById($userId)->is_admin);
    }

    public function testChangeDisplayName(): void
    {
        $a = $this->auth->register('a@example.com', 'Alpha', 'correct horse battery staple');
        $this->auth->register('b@example.com', 'Bravo', 'correct horse battery staple');

        // Taken name is rejected (case-insensitive).
        $this->assertFalse($this->auth->changeDisplayName($a['user']->user_id, 'bravo')['ok']);
        // Invalid characters rejected.
        $this->assertFalse($this->auth->changeDisplayName($a['user']->user_id, 'no@good')['ok']);
        // A free name succeeds.
        $this->assertTrue($this->auth->changeDisplayName($a['user']->user_id, 'Alphonse')['ok']);
        $this->assertSame('Alphonse', $this->userRepo->findById($a['user']->user_id)->display_name);
    }

    public function testChangePassword(): void
    {
        $reg = $this->auth->register('pw@example.com', 'Pwuser', 'correct horse battery staple');
        $this->auth->activate($this->tokenFromLink($reg['activation_link']));
        $id = $reg['user']->user_id;

        $this->assertFalse($this->auth->changePassword($id, 'wrong-current', 'a new long passphrase')['ok']);
        $this->assertFalse($this->auth->changePassword($id, 'correct horse battery staple', 'short')['ok']);
        $this->assertTrue($this->auth->changePassword($id, 'correct horse battery staple', 'a new long passphrase')['ok']);

        $this->assertSame('ok', $this->auth->authenticate('pw@example.com', 'a new long passphrase')['status']);
    }

    public function testDeleteAccountRequiresCorrectPassword(): void
    {
        $reg = $this->auth->register('del@example.com', 'Deleter', 'correct horse battery staple');
        $id = $reg['user']->user_id;

        $this->assertFalse($this->auth->deleteAccount($id, 'nope nope nope')['ok']);
        $this->assertNotNull($this->userRepo->findById($id));

        $this->assertTrue($this->auth->deleteAccount($id, 'correct horse battery staple')['ok']);
        $this->assertNull($this->userRepo->findById($id));
    }
}
