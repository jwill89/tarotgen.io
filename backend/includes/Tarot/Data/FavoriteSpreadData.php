<?php

namespace Tarot\Data;

use PDO;

/**
 * Data layer for the user_favorite_spreads table.
 * Manages a user's favorited spreads (both public and personal).
 */
class FavoriteSpreadData extends AbstractData
{
    /**
     * Get all favorite spread entries for a user.
     *
     * @return array<array{spread_type: string, spread_id: int}>
     */
    public function listByUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT spread_type, spread_id FROM user_favorite_spreads WHERE user_id = :user_id ORDER BY created_at ASC"
        );
        $stmt->execute([':user_id' => $userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Add a spread to the user's favorites. Silently ignores duplicates.
     */
    public function add(int $userId, string $spreadType, int $spreadId): bool
    {
        $stmt = $this->db->prepare(
            "INSERT OR IGNORE INTO user_favorite_spreads (user_id, spread_type, spread_id)
             VALUES (:user_id, :spread_type, :spread_id)"
        );

        return $stmt->execute([
            ':user_id'     => $userId,
            ':spread_type' => $spreadType,
            ':spread_id'   => $spreadId,
        ]);
    }

    /**
     * Remove a spread from the user's favorites.
     */
    public function remove(int $userId, string $spreadType, int $spreadId): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM user_favorite_spreads
             WHERE user_id = :user_id AND spread_type = :spread_type AND spread_id = :spread_id"
        );

        return $stmt->execute([
            ':user_id'     => $userId,
            ':spread_type' => $spreadType,
            ':spread_id'   => $spreadId,
        ]);
    }

    /**
     * Remove all favorites pointing to a specific spread (called when a spread is deleted).
     */
    public function removeBySpread(string $spreadType, int $spreadId): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM user_favorite_spreads WHERE spread_type = :spread_type AND spread_id = :spread_id"
        );

        return $stmt->execute([
            ':spread_type' => $spreadType,
            ':spread_id'   => $spreadId,
        ]);
    }
}

