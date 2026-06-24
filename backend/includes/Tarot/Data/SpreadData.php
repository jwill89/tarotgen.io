<?php

namespace Tarot\Data;

use JsonException;
use PDO;
use Tarot\Structure\Spread;

class SpreadData extends AbstractData
{
    use NormalizesPositions;

    /**
     * @return list<Spread>
     */
    public function retrieve(?int $spread_id = null): array
    {
        $query = "SELECT * FROM spreads";

        if ($spread_id !== null) {
            $query .= " WHERE spread_id = :spread_id";
        }

        $query .= " ORDER BY name";

        $stmt = $this->db->prepare($query);

        if ($spread_id !== null) {
            $stmt->bindParam(':spread_id', $spread_id, PDO::PARAM_INT);
        }

        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'hydrate'], $rows);
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

    /**
     * @throws JsonException
     */
    /**
     * @param array<string,mixed> $row
     */
    private function hydrate(array $row): Spread
    {
        $positions = json_decode($row['positions'] ?? '[]', true, 512, JSON_THROW_ON_ERROR);

        return new Spread([
            'spread_id'   => (int)$row['spread_id'],
            'name'        => (string)$row['name'],
            'description' => (string)$row['description'],
            'card_count'  => (int)$row['card_count'],
            'positions'   => is_array($positions) ? $positions : [],
        ]);
    }
}
