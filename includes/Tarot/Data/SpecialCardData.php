<?php

namespace Tarot\Data;

use PDO;
use Tarot\Database\Connection;
use Tarot\Structure\SpecialCard;

class SpecialCardData
{
    private PDO $db;

    public function __construct()
    {
        if (!isset($this->db)) {
            $this->db = Connection::getInstance();
        }
    }

    public function retrieve(int $deck_id, int $card_id): ?SpecialCard
    {
        // Initialize card data
        $card_data = null;

        // Setup Query
        $query = "SELECT * FROM special_cards WHERE deck_id = :deck_id AND card_id = :card_id";

        // Prepare statement
        $stmt = $this->db->prepare($query);

        // If prepared statement is successful
        if ($stmt) {
            // Bind ids
            $stmt->bindParam(':deck_id', $deck_id, PDO::PARAM_INT);
            $stmt->bindParam(':card_id', $card_id, PDO::PARAM_INT);

            // Try executing
            if ($stmt->execute()) {
                // Set Fetch Mode
                $stmt->setFetchMode(PDO::FETCH_CLASS, SpecialCard::class);

                // Fetch result
                $card_data = $stmt->fetch();
            }
        }

        return $card_data;
    }
}
