<?php

namespace Tarot\Data;

use PDO;
use Tarot\Structure\ChangelogEntry;

class ChangelogData extends AbstractData
{
    /**
     * @return ChangelogEntry[]
     */
    public function retrieve(?int $entry_id = null): array
    {
        $query = "SELECT * FROM changelog";

        if ($entry_id !== null) {
            $query .= " WHERE entry_id = :entry_id";
        }

        // Newest first: order by the entry date, then by id to break ties.
        $query .= " ORDER BY entry_date DESC, entry_id DESC";

        $stmt = $this->db->prepare($query);

        if ($entry_id !== null) {
            $stmt->bindParam(':entry_id', $entry_id, PDO::PARAM_INT);
        }

        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'hydrate'], $rows);
    }

    public function store(array $data): ?ChangelogEntry
    {
        $sql = "INSERT INTO changelog (title, body, entry_date)
                VALUES (:title, :body, :entry_date)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':title'      => $this->cleanTitle($data['title'] ?? ''),
            ':body'       => (string)($data['body'] ?? ''),
            ':entry_date' => $this->cleanDate($data['entry_date'] ?? ''),
        ]);

        $id = (int)$this->db->lastInsertId();
        $result = $this->retrieve($id);
        return $result[0] ?? null;
    }

    public function update(int $entry_id, array $data): ?ChangelogEntry
    {
        $fields = [];
        $params = [':entry_id' => $entry_id];

        if (array_key_exists('title', $data)) {
            $fields[] = 'title = :title';
            $params[':title'] = $this->cleanTitle($data['title']);
        }

        if (array_key_exists('body', $data)) {
            $fields[] = 'body = :body';
            $params[':body'] = (string)$data['body'];
        }

        if (array_key_exists('entry_date', $data)) {
            $fields[] = 'entry_date = :entry_date';
            $params[':entry_date'] = $this->cleanDate($data['entry_date']);
        }

        if (empty($fields)) {
            return null;
        }

        $sql = "UPDATE changelog SET " . implode(', ', $fields) . " WHERE entry_id = :entry_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $result = $this->retrieve($entry_id);
        return $result[0] ?? null;
    }

    public function delete(int $entry_id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM changelog WHERE entry_id = :entry_id");
        return $stmt->execute([':entry_id' => $entry_id]);
    }

    private function hydrate(array $row): ChangelogEntry
    {
        return new ChangelogEntry([
            'entry_id'   => (int)$row['entry_id'],
            'title'      => (string)$row['title'],
            'body'       => (string)$row['body'],
            'entry_date' => (string)$row['entry_date'],
        ]);
    }

    private function cleanTitle(mixed $title): string
    {
        return mb_substr(trim((string)$title), 0, 200);
    }

    /**
     * Accept a YYYY-MM-DD date; fall back to today when it can't be parsed so a
     * malformed value never breaks date-based ordering.
     */
    private function cleanDate(mixed $date): string
    {
        $date = trim((string)$date);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }

        return date('Y-m-d');
    }
}
