<?php

namespace Tarot\Structure;

use OpenApi\Attributes as OA;

/**
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OA\Schema(description: 'A tarot deck.')]
class Deck extends AbstractStructure
{
    /** Fallback standard card count when a deck's system is missing/orphaned. */
    public const int DEFAULT_TOTAL_CARDS = 78;

    #[OA\Property(type: 'integer')]
    public private(set) int $deck_id = 0;

    #[OA\Property(type: 'integer')]
    public private(set) int $deck_system_id = 1;

    #[OA\Property(type: 'string')]
    public private(set) string $name = '';

    #[OA\Property(type: 'string')]
    public private(set) string $artist = '';

    #[OA\Property(type: 'string')]
    public private(set) string $purchase_url = '';

    #[OA\Property(type: 'integer')]
    public private(set) int $additional_cards = 0;

    #[OA\Property(type: 'number', format: 'float')]
    public private(set) float $card_aspect_w = 5.0;

    #[OA\Property(type: 'number', format: 'float')]
    public private(set) float $card_aspect_h = 8.6;

    #[OA\Property(type: 'boolean')]
    public private(set) bool $approved = true;

    #[OA\Property(type: 'boolean')]
    public private(set) bool $usable = true;

    #[OA\Property(type: 'integer', nullable: true)]
    public private(set) ?int $submitted_by = null;

    /** Populated by JOIN queries — the deck system's short name. */
    #[OA\Property(type: 'string')]
    public private(set) string $system_short_name = '';

    /** Populated by JOIN queries — the deck system's total cards. */
    #[OA\Property(type: 'integer')]
    public private(set) int $system_total_cards = 0;

    /**
     * The authoritative standard card count for this deck: its deck system's
     * total, falling back to a standard 78-card tarot deck when the system is
     * missing (e.g. an orphaned deck whose system was deleted).
     */
    public function getEffectiveTotalCards(): int
    {
        return $this->system_total_cards > 0 ? $this->system_total_cards : self::DEFAULT_TOTAL_CARDS;
    }
}
