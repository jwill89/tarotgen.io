<?php

namespace Tarot\Service;

use Random\RandomException;
use Tarot\Repository\PluginAuthCodeRepository;
use Tarot\Repository\PluginTokenRepository;
use Tarot\Structure\PluginToken;

/**
 * The plugin account-linking engine (OAuth-style, PKCE public client).
 *
 * TarotGen.io is its own identity provider, so the flow is:
 *   1. The browser consent step calls {@see createAuthorizationCode()} with the
 *      plugin's PKCE `code_challenge`, and redirects the code back to the plugin's
 *      loopback listener.
 *   2. The plugin calls {@see exchangeCode()} with the code + its `code_verifier`;
 *      on a valid PKCE match we mint a long-lived Bearer token (returned once).
 *   3. Every authenticated request resolves via {@see resolveBearer()}.
 *
 * Only hashes are persisted; raw codes/tokens live only in transit. Revocation is
 * immediate (the token row is checked on every request).
 */
class PluginTokenService
{
    /** Human-visible prefix so a leaked token is greppable/identifiable. */
    private const string TOKEN_PREFIX = 'tg_pat_';

    /** Authorization codes are single-use and short-lived. */
    private const int AUTH_CODE_TTL_SECONDS = 300;

    private const string DEFAULT_SCOPE = 'account';

    public function __construct(
        private readonly PluginTokenRepository $tokens,
        private readonly PluginAuthCodeRepository $codes,
    ) {
    }

    /**
     * Mint a short-lived authorization code for a consenting, logged-in user,
     * bound to the plugin's PKCE challenge. Returns the raw code (shown to the
     * plugin via the loopback redirect).
     *
     * @throws RandomException
     */
    public function createAuthorizationCode(
        int $userId,
        string $codeChallenge,
        string $scope = self::DEFAULT_SCOPE
    ): string {
        $code = bin2hex(random_bytes(32));
        $this->codes->store(
            $userId,
            $this->hash($code),
            $codeChallenge,
            $scope,
            $this->expiresIn(self::AUTH_CODE_TTL_SECONDS)
        );

        return $code;
    }

    /**
     * Exchange an authorization code + PKCE verifier for a Bearer token. Returns
     * the raw token once, or null on any failure (unknown/expired/used code, or a
     * PKCE mismatch). The code is burned on a successful PKCE match so it can
     * never be replayed.
     *
     * @throws RandomException
     */
    public function exchangeCode(string $code, string $codeVerifier, string $label = 'TarotGen plugin'): ?string
    {
        if ($code === '' || $codeVerifier === '') {
            return null;
        }

        $row = $this->codes->findActive($this->hash($code));
        if ($row === null) {
            return null;
        }

        if (!$this->verifyChallenge($codeVerifier, $row['code_challenge'])) {
            return null;
        }

        // Single-use: burn the code before issuing so it can't be replayed.
        $this->codes->markUsed($row['id']);

        return $this->issueToken($row['user_id'], $row['scope'], $label);
    }

    /**
     * Mint and persist a Bearer token for a user. Returns the raw token once.
     *
     * @throws RandomException
     */
    public function issueToken(
        int $userId,
        string $scope = self::DEFAULT_SCOPE,
        string $label = 'TarotGen plugin'
    ): string {
        $token = self::TOKEN_PREFIX . bin2hex(random_bytes(32));
        $this->tokens->store($userId, $this->hash($token), $label, $scope, null);

        return $token;
    }

    /**
     * Resolve an `Authorization: Bearer …` header to a user id, or null when the
     * header is absent/malformed or the token is unknown/revoked/expired. Bumps
     * the token's last-used timestamp on success.
     */
    public function resolveBearer(string $authorizationHeader): ?int
    {
        $token = $this->extractBearer($authorizationHeader);
        if ($token === null) {
            return null;
        }

        $row = $this->tokens->findActive($this->hash($token));
        if ($row === null) {
            return null;
        }

        $this->tokens->touchLastUsed($row['id']);

        return $row['user_id'];
    }

    /**
     * A user's linked plugin tokens (for the "Connected Apps" list).
     *
     * @return list<PluginToken>
     */
    public function listForUser(int $userId): array
    {
        return $this->tokens->listForUser($userId);
    }

    /** Revoke one of a user's tokens. True only when a live token was revoked. */
    public function revoke(int $userId, int $tokenId): bool
    {
        return $this->tokens->revoke($tokenId, $userId);
    }

    /** Verify a PKCE `code_verifier` against the stored S256 `code_challenge`. */
    private function verifyChallenge(string $verifier, string $challenge): bool
    {
        $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return hash_equals($challenge, $computed);
    }

    /** Pull the raw token out of an `Authorization: Bearer <token>` header. */
    private function extractBearer(string $header): ?string
    {
        return preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $matches) === 1 ? $matches[1] : null;
    }

    private function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    private function expiresIn(int $seconds): string
    {
        return date('Y-m-d H:i:s', time() + $seconds);
    }
}
