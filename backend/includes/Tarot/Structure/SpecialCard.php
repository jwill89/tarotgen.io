<?php

namespace Tarot\Structure;

use OpenApi\Spec as OAS;

/**
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OAS\Schema(description: 'A deck-specific special (custom) card.')]
class SpecialCard extends AbstractStructure
{
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $deck_id = 0;

    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $card_id = 0;

    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $name = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string', nullable: true))]
    public private(set) ?string $keywords = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string', nullable: true))]
    public private(set) ?string $meaning = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string', nullable: true))]
    public private(set) ?string $advice = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string', nullable: true))]
    public private(set) ?string $keywords_reversed = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string', nullable: true))]
    public private(set) ?string $meaning_reversed = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string', nullable: true))]
    public private(set) ?string $advice_reversed = '';
}
