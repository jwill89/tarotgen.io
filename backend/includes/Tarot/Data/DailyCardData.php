<?php

namespace Tarot\Data;

use Tarot\Structure\DailyCard;

/**
 * The daily_cards table: which reading is a user's card for a day. The card
 * itself is read from the reading (its first drawn card), so the calendar shows
 * what the reading shows.
 */
class DailyCardData extends AbstractData
{
    /** A DailyCard, joined to its reading and (while it exists) its deck. */
    private const string SELECT
        = "SELECT dc.day, dc.reading_id, card.deck_id,
                  COALESCE(d.name, 'Deck #' || card.deck_id) AS deck_name,
                  card.card_id,
                  COALESCE(NULLIF(card.card_name, ''), 'Card #' || card.card_id) AS card_name,
                  card.reversed
           FROM daily_cards dc
           JOIN (
               SELECT reading_id,
                      CAST(COALESCE(json_extract(reading_info, '$.deck_id'), 0) AS INTEGER) AS deck_id,
                      CAST(COALESCE(json_extract(reading_info, '$.draw[0].card_id'), 0) AS INTEGER) AS card_id,
                      json_extract(reading_info, '$.draw[0].card_name') AS card_name,
                      COALESCE(json_extract(reading_info, '$.draw[0].reversed'), 0) <> 0 AS reversed
               FROM readings
           ) card ON card.reading_id = dc.reading_id
           LEFT JOIN decks d ON d.deck_id = card.deck_id";

    public function retrieve(int $userId, string $day): ?DailyCard
    {
        return $this->fetchOne(
            self::SELECT . ' WHERE dc.user_id = :uid AND dc.day = :day',
            [':uid' => $userId, ':day' => $day],
            DailyCard::class
        );
    }

    /**
     * A user's daily cards from $from up to (not including) $until, both "Y-m-d",
     * oldest first.
     *
     * @return list<DailyCard>
     */
    public function listBetween(int $userId, string $from, string $until): array
    {
        return $this->fetchAllAs(
            self::SELECT . ' WHERE dc.user_id = :uid AND dc.day >= :from AND dc.day < :until ORDER BY dc.day',
            [':uid' => $userId, ':from' => $from, ':until' => $until],
            DailyCard::class
        );
    }

    /**
     * Make $readingId the user's card for $day. False when the day already has
     * one: the first link stands (the primary key decides a race between two
     * draws for the same day).
     */
    public function link(int $userId, string $day, string $readingId): bool
    {
        $stmt = $this->db->prepare(
            'INSERT INTO daily_cards (user_id, day, reading_id) VALUES (:uid, :day, :rid)
             ON CONFLICT (user_id, day) DO NOTHING'
        );
        $stmt->execute([':uid' => $userId, ':day' => $day, ':rid' => $readingId]);

        return $stmt->rowCount() > 0;
    }
}
