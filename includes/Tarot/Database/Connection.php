<?php

namespace Tarot\Database;

use PDO;

final class Connection
{
    // Path to DB
    private const string PATH_TO_SQLITE_DB = "../db/tarotdb.db";
    private const string CRON_PATH_TO_SQLITE_DB = "db/tarotdb.db";

    // Access Through Connection
    private static PDO $conn;

    // Prevent New Object Instantiation
    private function __construct() {}

    // Prevent cloning
    private function __clone() {}

    public static function getInstance(): PDO
    {
        // If the connection isn't set, set it.
        if (!isset(self::$conn)) {
            // Determine the correct path
            $path = file_exists(self::PATH_TO_SQLITE_DB)
                ? self::PATH_TO_SQLITE_DB
                : self::CRON_PATH_TO_SQLITE_DB;

            self::$conn = new PDO("sqlite:" . $path);
            self::$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Enforce declared foreign keys (off by default in SQLite), wait a
            // few seconds instead of failing instantly when the file is locked,
            // and use WAL for better read/write concurrency.
            self::$conn->exec('PRAGMA foreign_keys = ON');
            self::$conn->exec('PRAGMA busy_timeout = 5000');
            self::$conn->exec('PRAGMA journal_mode = WAL');
        }

        return self::$conn;
    }
}
