<?php

namespace Tarot\Data;

use JsonException;
use PDO;
use Tarot\Structure\PendingSpread;

class PendingSpreadData extends AbstractData
{
    use NormalizesPositions;

    public function retrieve(?int $pending_id = null): array
    {
        // Resolve the submitter label from the linked account's current display
        // name; fall back to any legacy free-text name, then to "Guest".
        $query = "SELECT p.pending_id, p.name, p.description, p.card_count, p.positions,
                         p.submitted_at, p.user_id,
                         COALESCE(u.display_name, NULLIF(p.submitter, ''), 'Guest') AS submitter
                  FROM pending_spreads p
                  LEFT JOIN users u ON u.user_id = p.user_id";

        if ($pending_id !== null) {
            $query .= " WHERE p.pending_id = :pending_id";
        }

        // Oldest submissions first so the admin works the queue in order.
        $query .= " ORDER BY p.submitted_at ASC, p.pending_id ASC";

        $stmt = $this->db->prepare($query);

        if ($pending_id !== null) {
            $stmt->bindParam(':pending_id', $pending_id, PDO::PARAM_INT);
        }

        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * @throws JsonException
     */
    public function store(array $data, ?int $userId = null): ?PendingSpread
    {
        $positions = $this->normalizePositions($data['positions'] ?? []);

        // The submitter is no longer a free-text field — it's the linked account
        // (or "Guest"). The legacy `submitter` column is left at its default ''.
        $sql = "INSERT INTO pending_spreads (name, description, card_count, positions, user_id)
                VALUES (:name, :description, :card_count, :positions, :user_id)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':name'        => mb_substr(trim((string)($data['name'] ?? '')), 0, 200),
            ':description' => (string)($data['description'] ?? ''),
            ':card_count'  => (int)($data['card_count'] ?? count($positions)),
            ':positions'   => json_encode($positions, JSON_THROW_ON_ERROR),
            ':user_id'     => $userId,
        ]);

        $id = (int)$this->db->lastInsertId();
        $result = $this->retrieve($id);
        return $result[0] ?? null;
    }

    public function delete(int $pending_id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM pending_spreads WHERE pending_id = :pending_id");
        return $stmt->execute([':pending_id' => $pending_id]);
    }

    /**
     * @throws JsonException
     */
    private function hydrate(array $row): PendingSpread
    {
        $positions = json_decode($row['positions'] ?? '[]', true, 512, JSON_THROW_ON_ERROR);

        return new PendingSpread([
            'pending_id'   => (int)$row['pending_id'],
            'name'         => (string)$row['name'],
            'description'  => (string)$row['description'],
            'card_count'   => (int)$row['card_count'],
            'positions'    => is_array($positions) ? $positions : [],
            'submitter'    => (string)$row['submitter'],
            'user_id'      => $row['user_id'] !== null ? (int)$row['user_id'] : null,
            'submitted_at' => (string)$row['submitted_at'],
        ]);
    }
}
