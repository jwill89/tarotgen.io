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
     * active client publishing that identity that is most likely to be online
     * right now, with its consent tier. One install can publish many identities
     * (one per character), so this looks up the join table.
     *
     * If the same character is published by more than one client row (e.g. a
     * reinstall or a guest→account relink mints a fresh client and the old row is
     * never revoked), route to the one with the freshest CLIENT presence
     * (plugin_clients.last_seen, bumped on every inbox poll) so shares follow the
     * install that is actually running, not a stale/abandoned one. The identity
     * row's own last_seen (frozen at registration) is only a secondary tiebreak.
     * The server never handles the plaintext name here.
     *
     * @return array{client_id:int,accept_tier:string}|null
     */
    public function findByIdentity(string $identityHash): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT c.client_id, c.accept_tier
             FROM plugin_client_identities i
             JOIN plugin_clients c ON c.client_id = i.client_id
             WHERE i.identity_hash = :hash AND c.revoked_at IS NULL
             ORDER BY (c.last_seen IS NULL), c.last_seen DESC,
                      (i.last_seen IS NULL), i.last_seen DESC, c.client_id DESC LIMIT 1'
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

    /** Add (or refresh presence for) one of a client's published identities. */
    public function addIdentity(int $clientId, string $identityHash): void
    {
        $now  = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'INSERT INTO plugin_client_identities (client_id, identity_hash, last_seen, created_at)
             VALUES (:cid, :hash, :now, :now)
             ON CONFLICT(client_id, identity_hash) DO UPDATE SET last_seen = :now'
        );
        $stmt->execute([':cid' => $clientId, ':hash' => $identityHash, ':now' => $now]);
    }

    /** Remove one of a client's published identities (unpublish that character). */
    public function removeIdentity(int $clientId, string $identityHash): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM plugin_client_identities WHERE client_id = :cid AND identity_hash = :hash'
        );
        $stmt->execute([':cid' => $clientId, ':hash' => $identityHash]);
    }

    /**
     * Replace a client's published identity set with exactly $identityHashes:
     * add/refresh those listed, delete any that aren't. An empty list clears all.
     *
     * @param list<string> $identityHashes
     */
    public function syncIdentities(int $clientId, array $identityHashes): void
    {
        $this->db->beginTransaction();
        try {
            foreach ($identityHashes as $hash) {
                $this->addIdentity($clientId, $hash);
            }

            if ($identityHashes === []) {
                $stmt = $this->db->prepare('DELETE FROM plugin_client_identities WHERE client_id = :cid');
                $stmt->execute([':cid' => $clientId]);
            } else {
                $placeholders = implode(',', array_fill(0, count($identityHashes), '?'));
                $stmt = $this->db->prepare(
                    "DELETE FROM plugin_client_identities
                     WHERE client_id = ? AND identity_hash NOT IN ($placeholders)"
                );
                $stmt->execute(array_merge([$clientId], $identityHashes));
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** How many identities (characters) a client currently publishes. */
    public function identityCount(int $clientId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM plugin_client_identities WHERE client_id = :cid');
        $stmt->execute([':cid' => $clientId]);

        return (int)$stmt->fetchColumn();
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
