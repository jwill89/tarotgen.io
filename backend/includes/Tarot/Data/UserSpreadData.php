<?php

namespace Tarot\Data;

use JsonException;
use Tarot\Structure\UserSpread;

class UserSpreadData extends AbstractData
{
    use NormalizesPositions;

    /** Exactly the properties UserSpread declares (AUDIT §2.4). */
    private const string SELECT_COLS = 'user_spread_id, user_id, name, description, card_count, positions, '
        . 'created_at, updated_at';

    /**
     * Retrieve all spreads for a user, or a single one by ID (scoped to user).
     *
     * @return list<UserSpread>
     */
    public function retrieve(int $userId, ?int $userSpreadId = null): array
    {
        $query = "SELECT " . self::SELECT_COLS . " FROM user_spreads WHERE user_id = :user_id";
        $params = [':user_id' => $userId];

        if ($userSpreadId !== null) {
            $query .= " AND user_spread_id = :user_spread_id";
            $params[':user_spread_id'] = $userSpreadId;
        }

        return $this->fetchAllAs($query . ' ORDER BY created_at DESC', $params, UserSpread::class);
    }

    /**
     * Retrieve a single spread by its ID without user scoping (for use in readings).
     */
    public function findById(int $userSpreadId): ?UserSpread
    {
        return $this->fetchOne(
            'SELECT ' . self::SELECT_COLS . ' FROM user_spreads WHERE user_spread_id = :id',
            [':id' => $userSpreadId],
            UserSpread::class
        );
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
}
