<?php

namespace Tarot\Data;

use PDO;

/**
 * Data layer for the user_favorite_decks table.
 */
class FavoriteDeckData extends AbstractData
{
    /**
     * @return int[] deck IDs
     */
    public function listByUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT deck_id FROM user_favorite_decks WHERE user_id = :user_id ORDER BY created_at ASC"
        );
        $stmt->execute([':user_id' => $userId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function add(int $userId, int $deckId): bool
    {
        $stmt = $this->db->prepare(
            "INSERT OR IGNORE INTO user_favorite_decks (user_id, deck_id) VALUES (:user_id, :deck_id)"
        );

        return $stmt->execute([':user_id' => $userId, ':deck_id' => $deckId]);
    }

    public function remove(int $userId, int $deckId): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM user_favorite_decks WHERE user_id = :user_id AND deck_id = :deck_id"
        );

        return $stmt->execute([':user_id' => $userId, ':deck_id' => $deckId]);
    }
}
