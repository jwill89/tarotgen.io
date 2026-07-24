<?php

namespace Tarot\Database;

use PDO;

final class Connection
{
    // Access Through Connection
    private static PDO $conn;

    // Prevent New Object Instantiation
    private function __construct()
    {
    }

    // Prevent cloning
    private function __clone()
    {
    }

    public static function getInstance(): PDO
    {
        // If the connection isn't set, set it.
        if (!isset(self::$conn)) {
            // Anchor the DB path to this file's location (project_root/db) so it
            // resolves identically regardless of the caller's working directory
            // (web request from /, /api, the og.php shim, or a CLI/cron script).
            $path = dirname(__DIR__, 3) . '/db/tarotdb.db';

            self::$conn = new PDO("sqlite:" . $path);
            self::$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Enforce declared foreign keys (off by default in SQLite), wait a
            // few seconds instead of failing instantly when the file is locked,
            // and use WAL for better read/write concurrency.
            self::$conn->exec('PRAGMA foreign_keys = ON');
            self::$conn->exec('PRAGMA busy_timeout = 5000');
            self::$conn->exec('PRAGMA journal_mode = WAL');
            // With WAL, NORMAL is the recommended durability level: it drops the
            // per-transaction fsync (only syncing at checkpoints), which sharply
            // cuts write latency for the frequent session-row writes every
            // authenticated request makes. Trade-off: a transaction committed in
            // the last moment before an OS crash / power loss can roll back — no
            // corruption. Acceptable here; revert to FULL if strict durability is
            // required.
            self::$conn->exec('PRAGMA synchronous = NORMAL');
        }

        return self::$conn;
    }
}
