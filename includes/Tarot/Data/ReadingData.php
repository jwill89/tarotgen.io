<?php

namespace Tarot\Data;

use Exception;
use PDO;
use Tarot\Database\Connection;
use Tarot\Structure\Reading;

class ReadingData
{
    private PDO $db;

    public function __construct()
    {
        if (!isset($this->db)) {
            $this->db = Connection::getInstance();
        }
    }

    public function retrieve(string $reading_id): ?Reading
    {
        // Initialize card data
        $reading_data = null;

        // Setup Query
        $query = "SELECT * FROM readings WHERE reading_id = :reading_id";

        // Prepare statement
        $stmt = $this->db->prepare($query);

        // If prepared statement is successful
        if ($stmt) {
            // Bind reading_id
            $stmt->bindParam(':reading_id', $reading_id, PDO::PARAM_STR);

            // Try executing
            if ($stmt->execute()) {
                // Set Fetch Mode
                $stmt->setFetchMode(PDO::FETCH_CLASS, Reading::class);

                // Fetch result
                $reading_data = $stmt->fetch();

                // If failure, reset
                if (!$reading_data) { $reading_data = null; }
            }

            $stmt->closeCursor();
        }

        return $reading_data;
    }

    public function store(Reading $reading): ?Reading
    {
        // Setup Query
        $query = "INSERT INTO readings (reading_id, reading_info, reading_time) VALUES (:reading_id, :reading_info, CURRENT_TIMESTAMP)";

        // Prepare statement
        $stmt = $this->db->prepare($query);

        // If prepared statement is successful
        if ($stmt) {
            // Bind reading_id
            $stmt->bindParam(':reading_id', $reading->getReadingId(), PDO::PARAM_STR);
            $stmt->bindParam(':reading_info', $reading->getReadingInfo(), PDO::PARAM_STR);

            // Try executing
            if (!$stmt->execute()) {
                throw new Exception('Failed to store reading: ' . $this->db->errorInfo());
            }

            $stmt->closeCursor();
        }

        return $this->retrieve($reading->getReadingId());
    }
}
