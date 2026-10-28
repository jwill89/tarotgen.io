<?php

namespace Tarot\Structure;

use OpenApi\Spec as OAS;

/**
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OAS\Schema(description: 'A tarot deck system (a family of decks sharing a card structure).')]
class DeckSystem extends AbstractStructure
{
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $deck_system_id = 0;

    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $name = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $short_name = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $total_cards = 78;

    #[OAS\Property(schema: new OAS\Schema(type: 'boolean'))]
    public private(set) bool $approved = true;

    /** The submitter's user id: the public endpoints strip it (jsonSerializeWithout), so it isn't required. */
    #[OAS\Property(schema: new OAS\Schema(type: 'integer', nullable: true, description: 'The account that submitted it. Admin views only.'), x: ['admin-only' => true])]
    public private(set) ?int $submitted_by = null;
}
