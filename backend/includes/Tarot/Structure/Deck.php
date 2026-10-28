<?php

namespace Tarot\Structure;

use OpenApi\Spec as OAS;

/**
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OAS\Schema(description: 'A tarot deck.')]
class Deck extends AbstractStructure
{
    /** Fallback standard card count when a deck's system is missing/orphaned. */
    public const int DEFAULT_TOTAL_CARDS = 78;

    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $deck_id = 0;

    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $deck_system_id = 1;

    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $name = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $artist = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $purchase_url = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $additional_cards = 0;

    #[OAS\Property(schema: new OAS\Schema(type: 'number', format: 'float'))]
    public private(set) float $card_aspect_w = 5.0;

    #[OAS\Property(schema: new OAS\Schema(type: 'number', format: 'float'))]
    public private(set) float $card_aspect_h = 8.6;

    #[OAS\Property(schema: new OAS\Schema(type: 'boolean'))]
    public private(set) bool $approved = true;

    #[OAS\Property(schema: new OAS\Schema(type: 'boolean'))]
    public private(set) bool $usable = true;

    /** The submitter's user id: the public endpoints strip it (jsonSerializeWithout), so it isn't required. */
    #[OAS\Property(schema: new OAS\Schema(type: 'integer', nullable: true, description: 'The account that submitted it. Admin views only.'), x: ['admin-only' => true])]
    public private(set) ?int $submitted_by = null;

    /** Populated by JOIN queries — the deck system's short name. */
    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $system_short_name = '';

    /** Populated by JOIN queries — the deck system's total cards. */
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
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
