<?php

namespace Tarot\Structure;

use OpenApi\Attributes as OA;

/**
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OA\Schema(description: 'A card definition within a deck system.')]
class DeckSystemCard extends AbstractStructure
{
    #[OA\Property(type: 'integer')]
    public private(set) int $deck_system_id = 0;

    #[OA\Property(type: 'integer')]
    public private(set) int $card_id = 0;

    #[OA\Property(type: 'string')]
    public private(set) string $name = '';

    #[OA\Property(type: 'string', nullable: true)]
    public private(set) ?string $keywords = null;

    #[OA\Property(type: 'string', nullable: true)]
    public private(set) ?string $meaning = null;

    #[OA\Property(type: 'string', nullable: true)]
    public private(set) ?string $advice = null;

    #[OA\Property(type: 'string', nullable: true)]
    public private(set) ?string $reversed_keywords = null;

    #[OA\Property(type: 'string', nullable: true)]
    public private(set) ?string $reversed_meaning = null;

    #[OA\Property(type: 'string', nullable: true)]
    public private(set) ?string $reversed_advice = null;
}
