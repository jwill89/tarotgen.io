<?php

namespace Tarot\Data;

use PDO;
use Tarot\Structure\PluginClient;

/**
 * Storage for plugin relay clients: the per-install routing identity a share is
 * delivered to. Only the SHA-256 hash of a client token is stored; the raw value
 * is returned to the plugin exactly once at issue time. Recipient character/world
 * are self-published and only present while the install opts into addressing.
 */
class PluginClientData extends AbstractData
{
    /** Create a client row for a fresh token hash. Returns the new client_id. */
    public function create(string $tokenHash, ?int $userId): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO plugin_clients (token_hash, user_id, created_at)
             VALUES (:hash, :uid, :now)'
        );
        $stmt->execute([':hash' => $tokenHash, ':uid' => $userId, ':now' => date('Y-m-d H:i:s')]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * The active client row matching a token hash, or null (unknown/revoked).
     *
     * @return array{client_id:int,user_id:int|null}|null
     */
    public function findActive(string $tokenHash): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT client_id, user_id FROM plugin_clients
             WHERE token_hash = :hash AND revoked_at IS NULL LIMIT 1'
        );
        $stmt->execute([':hash' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return [
            'client_id' => (int)$row['client_id'],
            'user_id'   => $row['user_id'] === null ? null : (int)$row['user_id'],
        ];
    }

    /**
     * Resolve a keyed identity hash (of a Name@World) to a routing target — the
     * most recently seen active client publishing that identity, with its consent
     * tier. The server never handles the plaintext name here.
     *
     * @return array{client_id:int,accept_tier:string}|null
     */
    public function findByIdentity(string $identityHash): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT client_id, accept_tier FROM plugin_clients
             WHERE identity_hash = :hash AND revoked_at IS NULL
             ORDER BY (last_seen IS NULL), last_seen DESC, client_id DESC LIMIT 1'
        );
        $stmt->execute([':hash' => $identityHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return ['client_id' => (int)$row['client_id'], 'accept_tier' => (string)$row['accept_tier']];
    }

    /** The client's own view (for the settings screen / register echo). */
    public function findById(int $clientId): ?PluginClient
    {
        return $this->fetchOne(
            'SELECT client_id, user_id, accept_tier, last_seen, created_at, revoked_at
             FROM plugin_clients WHERE client_id = :id LIMIT 1',
            [':id' => $clientId],
            PluginClient::class
        );
    }

    /**
     * Publish (or clear, when null) the recipient identity hash for a client.
     * Passing null removes the install from addressing lookups.
     */
    public function setIdentity(int $clientId, ?string $identityHash): void
    {
        $stmt = $this->db->prepare('UPDATE plugin_clients SET identity_hash = :hash WHERE client_id = :id');
        $stmt->execute([':hash' => $identityHash, ':id' => $clientId]);
    }

    public function setAcceptTier(int $clientId, string $tier): void
    {
        $stmt = $this->db->prepare('UPDATE plugin_clients SET accept_tier = :tier WHERE client_id = :id');
        $stmt->execute([':tier' => $tier, ':id' => $clientId]);
    }

    /** Debounced-by-caller presence bump, piggybacked on the inbox poll. */
    public function touchLastSeen(int $clientId): void
    {
        $stmt = $this->db->prepare('UPDATE plugin_clients SET last_seen = :now WHERE client_id = :id');
        $stmt->execute([':now' => date('Y-m-d H:i:s'), ':id' => $clientId]);
    }

    /** Whether $ownerClientId has blocked $blockedClientId. */
    public function isBlocked(int $ownerClientId, int $blockedClientId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM plugin_blocks WHERE owner_client_id = :owner AND blocked_client_id = :blocked LIMIT 1'
        );
        $stmt->execute([':owner' => $ownerClientId, ':blocked' => $blockedClientId]);

        return $stmt->fetchColumn() !== false;
    }

    public function block(int $ownerClientId, int $blockedClientId): void
    {
        $stmt = $this->db->prepare(
            'INSERT OR IGNORE INTO plugin_blocks (owner_client_id, blocked_client_id, created_at)
             VALUES (:owner, :blocked, :now)'
        );
        $stmt->execute([':owner' => $ownerClientId, ':blocked' => $blockedClientId, ':now' => date('Y-m-d H:i:s')]);
    }

    public function unblock(int $ownerClientId, int $blockedClientId): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM plugin_blocks WHERE owner_client_id = :owner AND blocked_client_id = :blocked'
        );
        $stmt->execute([':owner' => $ownerClientId, ':blocked' => $blockedClientId]);
    }
}
