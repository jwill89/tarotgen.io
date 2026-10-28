<?php

namespace Tarot\Structure;

use OpenApi\Spec as OAS;

/**
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OAS\Schema(description: 'A card definition within a deck system.')]
class DeckSystemCard extends AbstractStructure
{
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $deck_system_id = 0;

    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $card_id = 0;

    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $name = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string', nullable: true))]
    public private(set) ?string $keywords = null;

    #[OAS\Property(schema: new OAS\Schema(type: 'string', nullable: true))]
    public private(set) ?string $meaning = null;

    #[OAS\Property(schema: new OAS\Schema(type: 'string', nullable: true))]
    public private(set) ?string $advice = null;

    #[OAS\Property(schema: new OAS\Schema(type: 'string', nullable: true))]
    public private(set) ?string $reversed_keywords = null;

    #[OAS\Property(schema: new OAS\Schema(type: 'string', nullable: true))]
    public private(set) ?string $reversed_meaning = null;

    #[OAS\Property(schema: new OAS\Schema(type: 'string', nullable: true))]
    public private(set) ?string $reversed_advice = null;
}
