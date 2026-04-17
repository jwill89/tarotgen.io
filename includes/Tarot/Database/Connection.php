<?php

namespace Tarot\Database;

use Exception, PDO;

class Connection
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
        }

        return self::$conn;
    }
}