<?php

namespace Tarot\Data;

use PDO;
use Tarot\Database\Connection;
use Tarot\Structure\Deck;

class DeckData
{
    private PDO $db;

    public function __construct()
    {
        if (!isset($this->db)) {
            $this->db = Connection::getInstance();
        }
    }

    public function retrieve(?int $deck_id = null): ?array
    {
        // Initialize deck data
        $deck_data = [];

        // Setup Query
        $query = "SELECT * FROM decks";

        // Add where clause if deck_id is set
        if ($deck_id) {
            $query .= " WHERE deck_id = :deck_id";
        }

        // Prepare statement
        $stmt = $this->db->prepare($query);

        // If prepared statement is successful
        if ($stmt) {
            // If deck_id is set, bind deck_id
            if ($deck_id) {
                $stmt->bindParam(':deck_id', $deck_id, PDO::PARAM_INT);
            }

            // Try executing
            if ($stmt->execute()) {
                // Fetch result
                $deck_data = $stmt->fetchAll(PDO::FETCH_CLASS, Deck::class);
            }
        }

        return $deck_data;
    }
}
