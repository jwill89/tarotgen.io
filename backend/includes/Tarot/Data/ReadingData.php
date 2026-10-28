<?php

namespace Tarot\Data;

use Tarot\Structure\Reading;
use Tarot\Structure\ReadingSummary;

class ReadingData extends AbstractData
{
    /**
     * Columns selected for every Reading read. Note `password_hash` is exposed
     * only as the derived `password_protected` flag — the hash itself is never
     * hydrated onto the Reading (so it can't leak through serialization).
     */
    private const string SELECT_COLS
        = 'reading_id, reading_info, reading_time, user_id, hide_user, reading_name, reading_notes, is_final,
         CASE WHEN password_hash IS NOT NULL AND password_hash <> \'\' THEN 1 ELSE 0 END AS password_protected';

    public function retrieve(string $reading_id): ?Reading
    {
        return $this->fetchOne(
            'SELECT ' . self::SELECT_COLS . ' FROM readings WHERE reading_id = :reading_id',
            [':reading_id' => $reading_id],
            Reading::class
        );
    }

    /**
     * A user's readings, newest first. With a $limit, one page of them (the
     * account page); without, all of them (what the plugin's list still expects).
     *
     * @return list<Reading>
     */
    public function listByUser(int $userId, ?int $limit = null, int $offset = 0): array
    {
        $sql = 'SELECT ' . self::SELECT_COLS . ' FROM readings WHERE user_id = :uid
                ORDER BY reading_time DESC, reading_id DESC';
        $params = [':uid' => $userId];
        if ($limit !== null) {
            $sql .= ' LIMIT :limit OFFSET :offset';
            $params += [':limit' => $limit, ':offset' => $offset];
        }

        return $this->fetchAllAs($sql, $params, Reading::class);
    }

    public function countByUser(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM readings WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);

        return (int)$stmt->fetchColumn();
    }

    public function store(Reading $reading, ?string $passwordHash = null): ?Reading
    {
        $stmt = $this->db->prepare(
            'INSERT INTO readings (reading_id, reading_info, reading_time, user_id, hide_user, reading_name, reading_notes, password_hash)
             VALUES (:reading_id, :reading_info, CURRENT_TIMESTAMP, :user_id, :hide_user, :reading_name, :reading_notes, :password_hash)'
        );
        $stmt->execute([
            ':reading_id'    => $reading->reading_id,
            ':reading_info'  => $reading->reading_info,
            ':user_id'       => $reading->user_id,
            ':hide_user'     => $reading->hide_user ? 1 : 0,
            ':reading_name'  => $reading->reading_name,
            ':reading_notes' => $reading->reading_notes,
            ':password_hash' => $passwordHash,
        ]);

        return $this->retrieve($reading->reading_id);
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
        if ($reading === null || $reading->user_id !== $userId) {
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
     * The page is cut first, so the JSON is read and the deck looked up only for
     * the rows shown.
     *
     * @return array{rows: list<ReadingSummary>, total: int}
     */
    public function listAll(int $limit, int $offset): array
    {
        $total = (int)$this->query('SELECT COUNT(*) FROM readings')->fetchColumn();

        $rows = $this->fetchAllAs(
            "SELECT page.reading_id, page.reading_time, page.user_id, page.display_name, page.deck_id,
                    COALESCE(d.name, 'Deck #' || page.deck_id) AS deck_name, page.spread_name
             FROM (
                 SELECT r.reading_id, r.reading_time, r.user_id,
                        COALESCE(u.display_name, 'Guest') AS display_name,
                        CAST(COALESCE(json_extract(r.reading_info, '$.deck_id'), 0) AS INTEGER) AS deck_id,
                        COALESCE(json_extract(r.reading_info, '$.spread.name'), '') AS spread_name
                 FROM readings r
                 LEFT JOIN users u ON r.user_id = u.user_id
                 ORDER BY r.reading_time DESC, r.reading_id DESC
                 LIMIT :limit OFFSET :offset
             ) page
             LEFT JOIN decks d ON d.deck_id = page.deck_id
             ORDER BY page.reading_time DESC, page.reading_id DESC",
            [':limit' => $limit, ':offset' => $offset],
            ReadingSummary::class
        );

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
}
