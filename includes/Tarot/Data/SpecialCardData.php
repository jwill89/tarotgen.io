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
        $this->db = Connection::getInstance();
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

    public function retrieveMultiple(int $deck_id, array $card_ids): array
    {
        $card_data = [];

        if (empty($card_ids)) {
            return $card_data;
        }

        // Setup Query with parameterized placeholders
        $placeholders = implode(', ', array_fill(0, count($card_ids), '?'));
        $query = "SELECT * FROM special_cards WHERE deck_id = ? AND card_id IN ($placeholders)";

        // Prepare statement
        $stmt = $this->db->prepare($query);

        if ($stmt) {
            // Bind deck_id first, then card_ids
            $params = array_merge([$deck_id], array_values($card_ids));
            if ($stmt->execute($params)) {
                $card_data = $stmt->fetchAll(PDO::FETCH_CLASS, SpecialCard::class);
            }
        }

        return $card_data;
    }
}
