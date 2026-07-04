<?php

namespace Tarot\Data;

use PDO;
use Tarot\Structure\PluginToken;

/**
 * Storage for plugin personal access tokens: long-lived, revocable, multi-use.
 * Only the SHA-256 hash of a token is stored; the raw value is returned to the
 * plugin exactly once at issue time.
 */
class PluginTokenData extends AbstractData
{
    public function store(int $userId, string $tokenHash, string $label, string $scope, ?string $expiresAt): int
    {
        $sql = 'INSERT INTO plugin_tokens (user_id, token_hash, label, scope, expires_at, created_at)
                VALUES (:uid, :hash, :label, :scope, :expires, :now)';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':uid'     => $userId,
            ':hash'    => $tokenHash,
            ':label'   => $label,
            ':scope'   => $scope,
            ':expires' => $expiresAt,
            ':now'     => date('Y-m-d H:i:s'),
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * The active token row matching the hash, or null. "Active" = not revoked and
     * not past its (optional) expiry.
     *
     * @return array{id:int,user_id:int}|null
     */
    public function findActive(string $tokenHash): ?array
    {
        $sql = 'SELECT id, user_id FROM plugin_tokens
                WHERE token_hash = :hash AND revoked_at IS NULL
                  AND (expires_at IS NULL OR expires_at > :now)
                LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':hash' => $tokenHash, ':now' => date('Y-m-d H:i:s')]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return ['id' => (int)$row['id'], 'user_id' => (int)$row['user_id']];
    }

    public function touchLastUsed(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE plugin_tokens SET last_used_at = :now WHERE id = :id');
        $stmt->execute([':now' => date('Y-m-d H:i:s'), ':id' => $id]);
    }

    /**
     * A user's tokens, newest first (for the "Connected Apps" listing).
     *
     * @return list<PluginToken>
     */
    public function listForUser(int $userId): array
    {
        return $this->fetchAllAs(
            'SELECT id, user_id, label, scope, created_at, last_used_at, expires_at, revoked_at
             FROM plugin_tokens WHERE user_id = :uid ORDER BY created_at DESC, id DESC',
            [':uid' => $userId],
            PluginToken::class
        );
    }

    /** Revoke one of a user's tokens. True only when a live token was actually revoked. */
    public function revoke(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE plugin_tokens SET revoked_at = :now
             WHERE id = :id AND user_id = :uid AND revoked_at IS NULL'
        );
        $stmt->execute([':now' => date('Y-m-d H:i:s'), ':id' => $id, ':uid' => $userId]);

        return $stmt->rowCount() > 0;
    }
}
