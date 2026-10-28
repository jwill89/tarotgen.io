<?php

namespace Tarot\Data;

use Tarot\Structure\PluginAuthCode;
use Tarot\Utility\Clock;

/**
 * Storage for short-lived, single-use PKCE authorization codes. The browser
 * consent step mints one bound to a user + code_challenge; the plugin exchanges
 * it (with the matching code_verifier) for a token. Only the SHA-256 hash of the
 * code is stored.
 */
class PluginAuthCodeData extends AbstractData
{
    public function store(int $userId, string $codeHash, string $codeChallenge, string $scope, string $expiresAt): bool
    {
        $sql = 'INSERT INTO plugin_auth_codes (user_id, code_hash, code_challenge, scope, expires_at, created_at)
                VALUES (:uid, :hash, :challenge, :scope, :expires, :now)';

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':uid'       => $userId,
            ':hash'      => $codeHash,
            ':challenge' => $codeChallenge,
            ':scope'     => $scope,
            ':expires'   => $expiresAt,
            ':now'       => Clock::now(),
        ]);
    }

    /**
     * An unused, unexpired code matching the hash, or null.
     */
    public function findActive(string $codeHash): ?PluginAuthCode
    {
        return $this->fetchOne(
            'SELECT id, user_id, code_challenge, scope FROM plugin_auth_codes
             WHERE code_hash = :hash AND used_at IS NULL AND expires_at > :now
             LIMIT 1',
            [':hash' => $codeHash, ':now' => Clock::now()],
            PluginAuthCode::class
        );
    }

    public function markUsed(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE plugin_auth_codes SET used_at = :now WHERE id = :id');
        return $stmt->execute([':now' => Clock::now(), ':id' => $id]);
    }
}
