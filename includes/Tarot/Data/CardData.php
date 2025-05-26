<?php

namespace Tarot\Data;

use PDO;
use Tarot\Database\Connection;
use Tarot\Structure\Card;

class CardData
{
    private PDO $db;

    public function __construct()
    {
        if (!isset($this->db)) {
            $this->db = Connection::getInstance();
        }
    }

    public function retrieve(int $card_id): ?Card
    {
        // Initialize card data
        $card_data = null;

        // Setup Query
        $query = "SELECT * FROM cards WHERE card_id = :card_id";

        // Prepare statement
        $stmt = $this->db->prepare($query);

        // If prepared statement is successful
        if ($stmt) {
            // Bind card_id
            $stmt->bindParam(':card_id', $card_id, PDO::PARAM_INT);

            // Try executing
            if ($stmt->execute()) {
                // Set Fetch Mode
                $stmt->setFetchMode(PDO::FETCH_CLASS, Card::class);

                // Fetch result
                $card_data = $stmt->fetch();
            }
        }

        return $card_data;
    }

    public function retrieveMultiple(array $card_ids): array
    {
        // Initialize card data
        $card_data = [];

        // Setup Query
        $query = "SELECT * FROM cards WHERE card_id = IN (" . implode(', ', $card_ids) . ")";

        // Prepare statement
        $stmt = $this->db->prepare($query);

        // If prepared statement is successful
        if ($stmt) {
            // Try executing
            if ($stmt->execute()) {
                // Fetch results
                $card_data = $stmt->fetchAll(PDO::FETCH_CLASS, Card::class);
            }
        }

        return $card_data;
    }
}
