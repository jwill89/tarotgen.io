<?php

namespace Tarot\Data;

use JsonException;
use PDO;
use Tarot\Structure\UserSpread;

class UserSpreadData extends AbstractData
{
    use NormalizesPositions;

    /**
     * Retrieve all spreads for a user, or a single one by ID (scoped to user).
     *
     * @return list<UserSpread>
     */
    public function retrieve(int $userId, ?int $userSpreadId = null): array
    {
        $query = "SELECT * FROM user_spreads WHERE user_id = :user_id";

        if ($userSpreadId !== null) {
            $query .= " AND user_spread_id = :user_spread_id";
        }

        $query .= " ORDER BY created_at DESC";

        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);

        if ($userSpreadId !== null) {
            $stmt->bindParam(':user_spread_id', $userSpreadId, PDO::PARAM_INT);
        }

        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * Retrieve a single spread by its ID without user scoping (for use in readings).
     */
    public function findById(int $userSpreadId): ?UserSpread
    {
        $stmt = $this->db->prepare("SELECT * FROM user_spreads WHERE user_spread_id = :id");
        $stmt->execute([':id' => $userSpreadId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * @throws JsonException
     */
    /**
     * @param array<string,mixed> $data
     */
    public function store(int $userId, array $data): ?UserSpread
    {
        $positions = $this->normalizePositions($data['positions'] ?? []);

        $sql = "INSERT INTO user_spreads (user_id, name, description, card_count, positions)
                VALUES (:user_id, :name, :description, :card_count, :positions)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id'     => $userId,
            ':name'        => mb_substr(trim((string)($data['name'] ?? '')), 0, 200),
            ':description' => (string)($data['description'] ?? ''),
            ':card_count'  => (int)($data['card_count'] ?? count($positions)),
            ':positions'   => json_encode($positions, JSON_THROW_ON_ERROR),
        ]);

        $id = (int)$this->db->lastInsertId();
        $result = $this->retrieve($userId, $id);
        return $result[0] ?? null;
    }

    /**
     * @throws JsonException
     */
    /**
     * @param array<string,mixed> $data
     */
    public function update(int $userId, int $userSpreadId, array $data): ?UserSpread
    {
        $fields = [];
        $params = [':user_spread_id' => $userSpreadId, ':user_id' => $userId];

        if (array_key_exists('name', $data)) {
            $fields[] = 'name = :name';
            $params[':name'] = mb_substr(trim((string)$data['name']), 0, 200);
        }

        if (array_key_exists('description', $data)) {
            $fields[] = 'description = :description';
            $params[':description'] = (string)$data['description'];
        }

        if (array_key_exists('positions', $data)) {
            $positions = $this->normalizePositions($data['positions']);
            $fields[] = 'positions = :positions';
            $params[':positions'] = json_encode($positions, JSON_THROW_ON_ERROR);

            if (!array_key_exists('card_count', $data)) {
                $fields[] = 'card_count = :card_count';
                $params[':card_count'] = count($positions);
            }
        }

        if (array_key_exists('card_count', $data)) {
            $fields[] = 'card_count = :card_count';
            $params[':card_count'] = (int)$data['card_count'];
        }

        if (empty($fields)) {
            return null;
        }

        $fields[] = "updated_at = CURRENT_TIMESTAMP";

        $sql = "UPDATE user_spreads SET " . implode(', ', $fields)
             . " WHERE user_spread_id = :user_spread_id AND user_id = :user_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $result = $this->retrieve($userId, $userSpreadId);
        return $result[0] ?? null;
    }

    public function delete(int $userId, int $userSpreadId): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM user_spreads WHERE user_spread_id = :user_spread_id AND user_id = :user_id"
        );
        return $stmt->execute([':user_spread_id' => $userSpreadId, ':user_id' => $userId]);
    }

    /**
     * @throws JsonException
     */
    /**
     * @param array<string,mixed> $row
     */
    private function hydrate(array $row): UserSpread
    {
        $positions = json_decode($row['positions'] ?? '[]', true, 512, JSON_THROW_ON_ERROR);

        return new UserSpread([
            'user_spread_id' => (int)$row['user_spread_id'],
            'user_id'        => (int)$row['user_id'],
            'name'           => (string)$row['name'],
            'description'    => (string)$row['description'],
            'card_count'     => (int)$row['card_count'],
            'positions'      => is_array($positions) ? $positions : [],
            'created_at'     => (string)$row['created_at'],
            'updated_at'     => (string)$row['updated_at'],
        ]);
    }
}
