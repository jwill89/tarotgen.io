<?php

namespace Tarot\Data;

use JsonException;
use Tarot\Structure\Spread;

class SpreadData extends AbstractData
{
    use NormalizesPositions;

    /** Exactly the properties Spread declares (AUDIT §2.4). */
    private const string SELECT_COLS = 'spread_id, name, description, card_count, positions';

    /**
     * @return list<Spread>
     */
    public function retrieve(?int $spread_id = null): array
    {
        $where = $spread_id !== null ? ' WHERE spread_id = :spread_id' : '';

        return $this->fetchAllAs(
            'SELECT ' . self::SELECT_COLS . ' FROM spreads' . $where . ' ORDER BY name',
            $spread_id !== null ? [':spread_id' => $spread_id] : [],
            Spread::class
        );
    }

    /**
     * @throws JsonException
     */
    /**
     * @param array<string,mixed> $data
     */
    public function store(array $data): ?Spread
    {
        $positions = $this->normalizePositions($data['positions'] ?? []);

        $sql = "INSERT INTO spreads (name, description, card_count, positions)
                VALUES (:name, :description, :card_count, :positions)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':name'        => $data['name'] ?? '',
            ':description' => $data['description'] ?? '',
            ':card_count'  => (int)($data['card_count'] ?? count($positions)),
            ':positions'   => json_encode($positions, JSON_THROW_ON_ERROR),
        ]);

        $id = (int)$this->db->lastInsertId();
        $result = $this->retrieve($id);
        return $result[0] ?? null;
    }

    /**
     * @throws JsonException
     */
    /**
     * @param array<string,mixed> $data
     */
    public function update(int $spread_id, array $data): ?Spread
    {
        $fields = [];
        $params = [':spread_id' => $spread_id];

        if (array_key_exists('name', $data)) {
            $fields[] = 'name = :name';
            $params[':name'] = (string)$data['name'];
        }

        if (array_key_exists('description', $data)) {
            $fields[] = 'description = :description';
            $params[':description'] = (string)$data['description'];
        }

        if (array_key_exists('positions', $data)) {
            $positions = $this->normalizePositions($data['positions']);
            $fields[] = 'positions = :positions';
            $params[':positions'] = json_encode($positions, JSON_THROW_ON_ERROR);

            // Keep card_count in sync with positions unless explicitly provided.
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

        $sql = "UPDATE spreads SET " . implode(', ', $fields) . " WHERE spread_id = :spread_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $result = $this->retrieve($spread_id);
        return $result[0] ?? null;
    }

    public function delete(int $spread_id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM spreads WHERE spread_id = :spread_id");
        return $stmt->execute([':spread_id' => $spread_id]);
    }
}
