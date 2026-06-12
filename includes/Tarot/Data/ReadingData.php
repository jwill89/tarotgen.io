<?php

namespace Tarot\Data;

use PDO;
use Tarot\Structure\Reading;

class ReadingData extends AbstractData
{
    /**
     * Columns selected for every Reading read. Note `password_hash` is exposed
     * only as the derived `password_protected` flag — the hash itself is never
     * hydrated onto the Reading (so it can't leak through serialization).
     */
    private const string SELECT_COLS =
        'reading_id, reading_info, reading_time, user_id, hide_user, reading_name, reading_notes, is_final,
         CASE WHEN password_hash IS NOT NULL AND password_hash <> \'\' THEN 1 ELSE 0 END AS password_protected';

    public function retrieve(string $reading_id): ?Reading
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::SELECT_COLS . ' FROM readings WHERE reading_id = :reading_id'
        );
        $stmt->execute([':reading_id' => $reading_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Every reading owned by a user, newest first — for the account dashboard.
     *
     * @return Reading[]
     */
    public function listByUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::SELECT_COLS . ' FROM readings WHERE user_id = :uid
             ORDER BY reading_time DESC, reading_id DESC'
        );
        $stmt->execute([':uid' => $userId]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function store(Reading $reading, ?string $passwordHash = null): ?Reading
    {
        $stmt = $this->db->prepare(
            'INSERT INTO readings (reading_id, reading_info, reading_time, user_id, hide_user, reading_name, reading_notes, password_hash)
             VALUES (:reading_id, :reading_info, CURRENT_TIMESTAMP, :user_id, :hide_user, :reading_name, :reading_notes, :password_hash)'
        );
        $stmt->execute([
            ':reading_id'    => $reading->getReadingId(),
            ':reading_info'  => $reading->getReadingInfo(),
            ':user_id'       => $reading->getUserId(),
            ':hide_user'     => $reading->isHideUser() ? 1 : 0,
            ':reading_name'  => $reading->getReadingName(),
            ':reading_notes' => $reading->getReadingNotes(),
            ':password_hash' => $passwordHash,
        ]);

        return $this->retrieve($reading->getReadingId());
    }

    /** Verify a view password against the stored hash. */
    public function verifyPassword(string $reading_id, string $password): bool
    {
        $stmt = $this->db->prepare('SELECT password_hash FROM readings WHERE reading_id = :id');
        $stmt->execute([':id' => $reading_id]);
        $hash = $stmt->fetchColumn();

        return is_string($hash) && $hash !== '' && password_verify($password, $hash);
    }

    /**
     * Update owner-editable metadata, scoped to the owner so a non-owner can't
     * change someone else's reading. Only keys present in $fields are written:
     *   - reading_name : string|null
     *   - hide_user    : bool
     *   - password_hash: string|null  (null clears the password)
     *
     * @param array<string,mixed> $fields
     * @return Reading|null  The updated reading, or null when nothing matched
     *                       (wrong owner / unknown id / no fields).
     */
    public function updateMeta(string $reading_id, int $userId, array $fields): ?Reading
    {
        $set    = [];
        $params = [':id' => $reading_id, ':uid' => $userId];

        if (array_key_exists('reading_name', $fields)) {
            $set[] = 'reading_name = :reading_name';
            $params[':reading_name'] = $fields['reading_name'];
        }
        if (array_key_exists('reading_notes', $fields)) {
            $set[] = 'reading_notes = :reading_notes';
            $params[':reading_notes'] = $fields['reading_notes'];
        }
        if (array_key_exists('hide_user', $fields)) {
            $set[] = 'hide_user = :hide_user';
            $params[':hide_user'] = $fields['hide_user'] ? 1 : 0;
        }
        if (array_key_exists('password_hash', $fields)) {
            $set[] = 'password_hash = :password_hash';
            $params[':password_hash'] = $fields['password_hash'];
        }

        if ($set === []) {
            return null;
        }

        $sql = 'UPDATE readings SET ' . implode(', ', $set)
             . ' WHERE reading_id = :id AND user_id = :uid';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            return null;
        }

        return $this->retrieve($reading_id);
    }

    /**
     * Update the reading_info JSON blob for a reading. Optionally scoped to a
     * specific owner (pass null to allow any reading — e.g. guest readings
     * identified by ID alone).
     */
    public function updateReadingInfo(string $reading_id, string $readingInfo, ?int $userId = null): ?Reading
    {
        if ($userId !== null) {
            $stmt = $this->db->prepare(
                'UPDATE readings SET reading_info = :info WHERE reading_id = :id AND user_id = :uid'
            );
            $stmt->execute([':info' => $readingInfo, ':id' => $reading_id, ':uid' => $userId]);
        } else {
            $stmt = $this->db->prepare(
                'UPDATE readings SET reading_info = :info WHERE reading_id = :id'
            );
            $stmt->execute([':info' => $readingInfo, ':id' => $reading_id]);
        }

        if ($stmt->rowCount() === 0) {
            return null;
        }

        return $this->retrieve($reading_id);
    }

    /**
     * Mark a reading as final (locked against further draws), scoped to its
     * owner. One-way: the flag is never cleared. Returns the updated reading, or
     * null when nothing matched (wrong owner / unknown id).
     */
    public function markFinal(string $reading_id, int $userId): ?Reading
    {
        $stmt = $this->db->prepare(
            'UPDATE readings SET is_final = 1 WHERE reading_id = :id AND user_id = :uid'
        );
        $stmt->execute([':id' => $reading_id, ':uid' => $userId]);

        // rowCount() is 0 when the value was already 1, so re-read rather than
        // relying on it. Report success only when the row belongs to this owner
        // (guards against an unknown id or a non-owner caller).
        $reading = $this->retrieve($reading_id);
        if ($reading === null || $reading->getUserId() !== $userId) {
            return null;
        }

        return $reading;
    }

    /** Delete a reading, scoped to its owner. Returns true when a row was removed. */
    public function deleteForOwner(string $reading_id, int $userId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM readings WHERE reading_id = :id AND user_id = :uid');
        $stmt->execute([':id' => $reading_id, ':uid' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /** Admin: delete any reading regardless of owner. */
    public function delete(string $reading_id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM readings WHERE reading_id = :id');
        $stmt->execute([':id' => $reading_id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Admin: paginated list of all readings (lightweight — no reading_info body).
     *
     * @return array{rows: array, total: int}
     */
    public function listAll(int $limit, int $offset): array
    {
        $total = (int)$this->db->query('SELECT COUNT(*) FROM readings')->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT r.reading_id, r.reading_info, r.reading_time, r.user_id,
                    u.display_name
             FROM readings r
             LEFT JOIN users u ON r.user_id = u.user_id
             ORDER BY r.reading_time DESC, r.reading_id DESC
             LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $info = json_decode((string)$row['reading_info'], true, 512, JSON_THROW_ON_ERROR);
            $rows[] = [
                'reading_id'   => (string)$row['reading_id'],
                'reading_time' => (string)$row['reading_time'],
                'user_id'      => $row['user_id'] !== null ? (int)$row['user_id'] : null,
                'display_name' => $row['display_name'] !== null ? (string)$row['display_name'] : 'Guest',
                'deck_id'      => (int)($info['deck_id'] ?? 0),
                'spread_name'  => (string)($info['spread']['name'] ?? ''),
            ];
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Admin: delete guest (unowned) readings older than a given number of days.
     *
     * @return int Number of rows deleted.
     */
    public function cleanGuestOlderThan(int $days): int
    {
        $stmt = $this->db->prepare(
            "DELETE FROM readings
             WHERE user_id IS NULL
               AND reading_time < datetime('now', :interval)"
        );
        $stmt->execute([':interval' => "-{$days} days"]);

        return $stmt->rowCount();
    }

    private function hydrate(array $row): Reading
    {
        $reading = new Reading();
        $reading->setReadingId((string)$row['reading_id']);
        $reading->setReadingInfo((string)$row['reading_info']);
        $reading->setReadingTime((string)$row['reading_time']);
        $reading->setUserId($row['user_id'] !== null ? (int)$row['user_id'] : null);
        $reading->setHideUser((bool)(int)$row['hide_user']);
        $reading->setReadingName($row['reading_name'] !== null ? (string)$row['reading_name'] : null);
        $reading->setReadingNotes($row['reading_notes'] !== null ? (string)$row['reading_notes'] : null);
        $reading->setIsFinal((bool)(int)($row['is_final'] ?? 0));
        $reading->setPasswordProtected((bool)(int)$row['password_protected']);

        return $reading;
    }
}
