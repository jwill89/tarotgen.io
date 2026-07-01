<?php

namespace Tarot\Structure;

use OpenApi\Attributes as OA;

/**
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OA\Schema(description: 'A deck-specific special (custom) card.')]
class SpecialCard extends AbstractStructure
{
    #[OA\Property(type: 'integer')]
    public private(set) int $deck_id = 0;

    #[OA\Property(type: 'integer')]
    public private(set) int $card_id = 0;

    #[OA\Property(type: 'string')]
    public private(set) string $name = '';

    #[OA\Property(type: 'string', nullable: true)]
    public private(set) ?string $keywords = '';

    #[OA\Property(type: 'string', nullable: true)]
    public private(set) ?string $meaning = '';

    #[OA\Property(type: 'string', nullable: true)]
    public private(set) ?string $advice = '';

    #[OA\Property(type: 'string', nullable: true)]
    public private(set) ?string $keywords_reversed = '';

    #[OA\Property(type: 'string', nullable: true)]
    public private(set) ?string $meaning_reversed = '';

    #[OA\Property(type: 'string', nullable: true)]
    public private(set) ?string $advice_reversed = '';
}
