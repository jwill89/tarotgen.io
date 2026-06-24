<?php

namespace Tarot\Data;

use PDO;

/**
 * Shared database plumbing for the Data layer: a PDO handle plus a couple of
 * helpers that remove the repetitive prepare/execute/fetch boilerplate and the
 * hand-rolled "dynamic UPDATE from an allow-list" pattern.
 *
 * The PDO handle is injected so the Data layer never reaches for a global
 * singleton, which keeps it isolatable in tests (pass an in-memory SQLite PDO).
 */
abstract class AbstractData
{
    protected PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Fetch a single row hydrated into $class, or null when there is no match.
     *
     * @template T of object
     * @param array<array-key,mixed> $params Bound statement params (named or positional).
     * @param class-string<T> $class
     * @return T|null
     */
    protected function fetchOne(string $sql, array $params, string $class): ?object
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $stmt->setFetchMode(PDO::FETCH_CLASS, $class);

        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Fetch every matching row hydrated into $class.
     *
     * @template T of object
     * @param array<array-key,mixed> $params Bound statement params (named or positional).
     * @param class-string<T> $class
     * @return list<T>
     */
    protected function fetchAllAs(string $sql, array $params, string $class): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_CLASS, $class);
    }

    /**
     * Build and run a dynamic UPDATE limited to an allow-list of columns.
     * Returns true when at least one column was written.
     *
     * @param array<string,mixed> $data     Incoming field => value pairs.
     * @param string[]            $allowed  Columns that may be updated.
     * @param array<string,mixed> $where    Column => value identifying the row(s).
     * @param string[]            $intCols  Columns cast to int.
     * @param string[]            $boolCols Columns cast to a 0/1 int.
     */
    protected function applyUpdate(
        string $table,
        array $data,
        array $allowed,
        array $where,
        array $intCols = [],
        array $boolCols = []
    ): bool {
        $fields = [];
        $params = [];

        foreach ($data as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }

            $fields[] = "{$key} = :{$key}";

            if (in_array($key, $boolCols, true)) {
                $params[":{$key}"] = (int)(bool)$value;
            } elseif (in_array($key, $intCols, true)) {
                $params[":{$key}"] = (int)$value;
            } else {
                $params[":{$key}"] = $value;
            }
        }

        if (empty($fields)) {
            return false;
        }

        // WHERE placeholders are prefixed so they never collide with field names.
        $conditions = [];
        foreach ($where as $col => $val) {
            $conditions[] = "{$col} = :w_{$col}";
            $params[":w_{$col}"] = $val;
        }

        $sql = "UPDATE {$table} SET " . implode(', ', $fields)
             . " WHERE " . implode(' AND ', $conditions);

        $this->db->prepare($sql)->execute($params);

        return true;
    }
}
