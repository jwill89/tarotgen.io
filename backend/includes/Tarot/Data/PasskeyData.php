<?php

namespace Tarot\Data;

use PDO;

class PasskeyData extends AbstractData
{
    /**
     * Get all passkeys for a user.
     * @return array<array{passkey_id:int, credential_id:string, name:string, created_at:string, last_used_at:?string}>
     */
    public function getByUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT passkey_id, credential_id, name, created_at, last_used_at
             FROM user_passkeys WHERE user_id = :uid ORDER BY created_at DESC'
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find a passkey by its credential ID (base64url-encoded).
     * @return array{passkey_id:int, user_id:int, credential_id:string, public_key_pem:string, sign_count:int}|null
     */
    public function findByCredentialId(string $credentialId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT passkey_id, user_id, credential_id, public_key_pem, sign_count
             FROM user_passkeys WHERE credential_id = :cid'
        );
        $stmt->execute([':cid' => $credentialId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Get all credential IDs for a user (binary format for WebAuthn library).
     * @return string[]
     */
    public function getCredentialIds(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT credential_id FROM user_passkeys WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Store a new passkey credential.
     */
    public function create(int $userId, string $credentialId, string $publicKeyPem, string $name, int $signCount): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO user_passkeys (user_id, credential_id, public_key_pem, name, sign_count, created_at)
             VALUES (:uid, :cid, :pem, :name, :sc, :now)'
        );
        $stmt->execute([
            ':uid'  => $userId,
            ':cid'  => $credentialId,
            ':pem'  => $publicKeyPem,
            ':name' => $name,
            ':sc'   => $signCount,
            ':now'  => date('Y-m-d H:i:s'),
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Update signature counter and last-used timestamp after a successful authentication.
     */
    public function updateSignCount(int $passkeyId, int $signCount): void
    {
        $stmt = $this->db->prepare(
            'UPDATE user_passkeys SET sign_count = :sc, last_used_at = :now WHERE passkey_id = :id'
        );
        $stmt->execute([':sc' => $signCount, ':now' => date('Y-m-d H:i:s'), ':id' => $passkeyId]);
    }

    /**
     * Rename a passkey.
     */
    public function rename(int $passkeyId, int $userId, string $name): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE user_passkeys SET name = :name WHERE passkey_id = :id AND user_id = :uid'
        );
        $stmt->execute([':name' => $name, ':id' => $passkeyId, ':uid' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a passkey.
     */
    public function delete(int $passkeyId, int $userId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM user_passkeys WHERE passkey_id = :id AND user_id = :uid');
        $stmt->execute([':id' => $passkeyId, ':uid' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Count passkeys for a user.
     */
    public function countByUser(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM user_passkeys WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        return (int)$stmt->fetchColumn();
    }
}

