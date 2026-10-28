<?php

namespace Tarot\Data;

use Throwable;
use Tarot\Structure\Deck;

class DeckData extends AbstractData
{
    // Columns are listed, not d.*: FETCH_CLASS turns any column Deck doesn't
    // declare into a dynamic property (deprecated, fatal in PHP 9), which is how
    // the dropped legacy columns broke dev DBs built from a stale schema (AUDIT §1.2).
    private const string SELECT_WITH_SYSTEM = "
        SELECT d.deck_id, d.deck_system_id, d.name, d.artist, d.purchase_url, d.additional_cards,
               d.card_aspect_w, d.card_aspect_h, d.approved, d.usable, d.submitted_by,
               ds.short_name AS system_short_name, ds.total_cards AS system_total_cards
        FROM decks d
        LEFT JOIN deck_systems ds ON ds.deck_system_id = d.deck_system_id
    ";

    /**
     * @return list<Deck>
     */
    public function retrieve(?int $deck_id = null): array
    {
        if ($deck_id !== null) {
            return $this->fetchAllAs(
                self::SELECT_WITH_SYSTEM . " WHERE d.deck_id = :deck_id",
                [':deck_id' => $deck_id],
                Deck::class
            );
        }

        return $this->fetchAllAs(self::SELECT_WITH_SYSTEM, [], Deck::class);
    }

    /**
     * Retrieve only decks marked as usable (for public-facing selectors).
     *
     * @return list<Deck>
     */
    public function retrieveUsable(): array
    {
        return $this->fetchAllAs(self::SELECT_WITH_SYSTEM . " WHERE d.usable = 1", [], Deck::class);
    }

    /**
     * Retrieve decks that are approved but not necessarily usable (for admin approved list).
     *
     * @return list<Deck>
     */
    public function retrieveApproved(): array
    {
        return $this->fetchAllAs(self::SELECT_WITH_SYSTEM . " WHERE d.approved = 1", [], Deck::class);
    }

    /**
     * Retrieve decks that are NOT approved (submitted, pending review).
     *
     * @return list<Deck>
     */
    public function retrievePending(): array
    {
        return $this->fetchAllAs(self::SELECT_WITH_SYSTEM . " WHERE d.approved = 0", [], Deck::class);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function store(array $data): ?Deck
    {
        $sql = "INSERT INTO decks (name, artist, purchase_url, deck_system_id, additional_cards, card_aspect_w, card_aspect_h, approved, usable, submitted_by)
                VALUES (:name, :artist, :purchase_url, :deck_system_id, :additional_cards, :card_aspect_w, :card_aspect_h, :approved, :usable, :submitted_by)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':name'             => $data['name'] ?? '',
            ':artist'           => $data['artist'] ?? '',
            ':purchase_url'     => $data['purchase_url'] ?? '',
            ':deck_system_id'   => (int)($data['deck_system_id'] ?? 1),
            ':additional_cards' => (int)($data['additional_cards'] ?? 0),
            ':card_aspect_w'    => $this->clampAspect($data['card_aspect_w'] ?? 5),
            ':card_aspect_h'    => $this->clampAspect($data['card_aspect_h'] ?? 8.6),
            ':approved'         => (int)($data['approved'] ?? true),
            ':usable'           => (int)($data['usable'] ?? false),
            ':submitted_by'     => $data['submitted_by'] ?? null,
        ]);

        $id = (int)$this->db->lastInsertId();
        $result = $this->retrieve($id);

        return $result[0] ?? null;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update(int $deck_id, array $data): ?Deck
    {
        // Clamp aspect components to a sane positive range before persisting.
        foreach (['card_aspect_w', 'card_aspect_h'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = $this->clampAspect($data[$key]);
            }
        }

        $allowed   = ['name', 'artist', 'purchase_url', 'deck_system_id', 'additional_cards', 'card_aspect_w', 'card_aspect_h', 'approved', 'usable'];
        $boolCols  = ['approved', 'usable'];
        $intCols   = ['additional_cards', 'deck_system_id'];

        if (!$this->applyUpdate('decks', $data, $allowed, ['deck_id' => $deck_id], $intCols, $boolCols)) {
            return null;
        }

        $result = $this->retrieve($deck_id);

        return $result[0] ?? null;
    }

    /**
     * Keep aspect components positive and sane.
     */
    private function clampAspect(mixed $v): float
    {
        return max(0.1, min(100000.0, (float)$v));
    }

    /**
     * Delete a deck. Its special cards, card reports and favourites go with it:
     * every table that references decks does so ON DELETE CASCADE (special_cards
     * since db/migrate_special_cards_cascade.php, AUDIT §2.7).
     */
    public function delete(int $deck_id): bool
    {
        try {
            return $this->db->prepare('DELETE FROM decks WHERE deck_id = :deck_id')
                ->execute([':deck_id' => $deck_id]);
        } catch (Throwable) {
            return false;
        }
    }
}
