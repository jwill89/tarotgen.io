<?php

namespace Tarot\Data;

use PDO;

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
            ':now'       => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * An unused, unexpired code row matching the hash, or null.
     *
     * @return array{id:int,user_id:int,code_challenge:string,scope:string}|null
     */
    public function findActive(string $codeHash): ?array
    {
        $sql = 'SELECT id, user_id, code_challenge, scope FROM plugin_auth_codes
                WHERE code_hash = :hash AND used_at IS NULL AND expires_at > :now
                LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':hash' => $codeHash, ':now' => date('Y-m-d H:i:s')]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return [
            'id'             => (int)$row['id'],
            'user_id'        => (int)$row['user_id'],
            'code_challenge' => (string)$row['code_challenge'],
            'scope'          => (string)$row['scope'],
        ];
    }

    public function markUsed(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE plugin_auth_codes SET used_at = :now WHERE id = :id');
        return $stmt->execute([':now' => date('Y-m-d H:i:s'), ':id' => $id]);
    }
}
