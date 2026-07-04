<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Service;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Data\PluginAuthCodeData;
use Tarot\Data\PluginTokenData;
use Tarot\Repository\PluginAuthCodeRepository;
use Tarot\Repository\PluginTokenRepository;
use Tarot\Service\PluginTokenService;

/**
 * Exercises the plugin account-linking engine against an in-memory SQLite DB:
 * the PKCE authorization-code → token exchange, Bearer resolution, single-use +
 * expiry semantics, and revocation.
 */
#[CoversClass(PluginTokenService::class)]
#[CoversClass(PluginTokenData::class)]
#[CoversClass(PluginAuthCodeData::class)]
final class PluginTokenServiceTest extends TestCase
{
    private PDO $pdo;
    private PluginTokenService $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec(
            "CREATE TABLE plugin_tokens (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id      INTEGER NOT NULL,
                token_hash   TEXT    NOT NULL UNIQUE,
                label        TEXT    NOT NULL DEFAULT 'TarotGen plugin',
                scope        TEXT    NOT NULL DEFAULT 'account',
                created_at   TEXT    NOT NULL,
                last_used_at TEXT    DEFAULT NULL,
                expires_at   TEXT    DEFAULT NULL,
                revoked_at   TEXT    DEFAULT NULL
            )"
        );
        $this->pdo->exec(
            "CREATE TABLE plugin_auth_codes (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id        INTEGER NOT NULL,
                code_hash      TEXT    NOT NULL UNIQUE,
                code_challenge TEXT    NOT NULL,
                scope          TEXT    NOT NULL DEFAULT 'account',
                created_at     TEXT    NOT NULL,
                expires_at     TEXT    NOT NULL,
                used_at        TEXT    DEFAULT NULL
            )"
        );

        $this->service = new PluginTokenService(
            new PluginTokenRepository(new PluginTokenData($this->pdo)),
            new PluginAuthCodeRepository(new PluginAuthCodeData($this->pdo)),
        );
    }

    /** S256 challenge for a verifier, matching RFC 7636. */
    private function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public function testFullPkceFlowIssuesAndResolvesToken(): void
    {
        $verifier = 'verifier-' . str_repeat('a', 43);
        $code = $this->service->createAuthorizationCode(7, $this->challengeFor($verifier));

        $token = $this->service->exchangeCode($code, $verifier);

        $this->assertNotNull($token);
        $this->assertStringStartsWith('tg_pat_', $token);
        $this->assertSame(7, $this->service->resolveBearer('Bearer ' . $token));
    }

    public function testExchangeFailsWithWrongVerifier(): void
    {
        $code = $this->service->createAuthorizationCode(1, $this->challengeFor('the-real-verifier'));

        $this->assertNull($this->service->exchangeCode($code, 'a-different-verifier'));
    }

    public function testAuthorizationCodeIsSingleUse(): void
    {
        $verifier = 'single-use-verifier';
        $code = $this->service->createAuthorizationCode(1, $this->challengeFor($verifier));

        $this->assertNotNull($this->service->exchangeCode($code, $verifier));
        // Second exchange of the same code must fail (code was burned).
        $this->assertNull($this->service->exchangeCode($code, $verifier));
    }

    public function testExpiredAuthorizationCodeIsRejected(): void
    {
        // Insert a code that expired in the past, then try to exchange it.
        $verifier = 'expired-verifier';
        $code = 'expired-code-value';
        $this->pdo->prepare(
            "INSERT INTO plugin_auth_codes (user_id, code_hash, code_challenge, scope, created_at, expires_at)
             VALUES (1, :hash, :challenge, 'account', :now, :past)"
        )->execute([
            ':hash'      => hash('sha256', $code),
            ':challenge' => $this->challengeFor($verifier),
            ':now'       => date('Y-m-d H:i:s', time() - 600),
            ':past'      => date('Y-m-d H:i:s', time() - 60),
        ]);

        $this->assertNull($this->service->exchangeCode($code, $verifier));
    }

    public function testResolveBearerRejectsUnknownToken(): void
    {
        $this->assertNull($this->service->resolveBearer('Bearer tg_pat_deadbeef'));
    }

    public function testResolveBearerRejectsMalformedHeaders(): void
    {
        $this->assertNull($this->service->resolveBearer(''));
        $this->assertNull($this->service->resolveBearer('Basic abc123'));
        $this->assertNull($this->service->resolveBearer('Bearer'));
    }

    public function testRevokeInvalidatesToken(): void
    {
        $verifier = 'revoke-verifier';
        $code = $this->service->createAuthorizationCode(42, $this->challengeFor($verifier));
        $token = $this->service->exchangeCode($code, $verifier);
        $this->assertNotNull($token);

        $tokens = $this->service->listForUser(42);
        $this->assertCount(1, $tokens);
        $this->assertTrue($tokens[0]->isActive());

        $this->assertTrue($this->service->revoke(42, $tokens[0]->id));
        $this->assertNull($this->service->resolveBearer('Bearer ' . $token));

        // A second revoke is a no-op (already revoked).
        $this->assertFalse($this->service->revoke(42, $tokens[0]->id));
    }

    public function testRevokeIsScopedToTheOwningUser(): void
    {
        $verifier = 'owner-verifier';
        $code = $this->service->createAuthorizationCode(1, $this->challengeFor($verifier));
        $token = $this->service->exchangeCode($code, $verifier);
        $this->assertNotNull($token);
        $tokenId = $this->service->listForUser(1)[0]->id;

        // A different user cannot revoke it, and it keeps working.
        $this->assertFalse($this->service->revoke(999, $tokenId));
        $this->assertSame(1, $this->service->resolveBearer('Bearer ' . $token));
    }

    public function testListReturnsOnlyTheUsersTokens(): void
    {
        $this->service->issueToken(1);
        $this->service->issueToken(1);
        $this->service->issueToken(2);

        $this->assertCount(2, $this->service->listForUser(1));
        $this->assertCount(1, $this->service->listForUser(2));
    }
}
