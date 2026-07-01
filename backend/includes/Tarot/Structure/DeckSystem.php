<?php

namespace Tarot\Structure;

use OpenApi\Attributes as OA;

/**
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OA\Schema(description: 'A tarot deck system (a family of decks sharing a card structure).')]
class DeckSystem extends AbstractStructure
{
    #[OA\Property(type: 'integer')]
    public private(set) int $deck_system_id = 0;

    #[OA\Property(type: 'string')]
    public private(set) string $name = '';

    #[OA\Property(type: 'string')]
    public private(set) string $short_name = '';

    #[OA\Property(type: 'integer')]
    public private(set) int $total_cards = 78;

    #[OA\Property(type: 'boolean')]
    public private(set) bool $approved = true;

    #[OA\Property(type: 'integer', nullable: true)]
    public private(set) ?int $submitted_by = null;
}
