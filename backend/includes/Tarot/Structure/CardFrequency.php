<?php

namespace Tarot\Structure;

use OpenApi\Spec as OAS;

/**
 * Which cards keep turning up in a user's own readings, optionally within one
 * deck and a recent window: what GET /account/card-frequency answers. Assembled
 * by CardFrequencyRepository from two CardFrequencyData queries.
 *
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OAS\Schema(description: "How often each card turned up in the signed-in user's readings.")]
class CardFrequency extends AbstractStructure
{
    #[OAS\Property(schema: new OAS\Schema(description: 'Readings counted: those matching the deck and window.', type: 'integer'))]
    public private(set) int $readings = 0;

    #[OAS\Property(schema: new OAS\Schema(description: 'The deck counted, or null for every deck.', type: 'integer', nullable: true))]
    public private(set) ?int $deck_id = null;

    #[OAS\Property(schema: new OAS\Schema(description: 'The window counted, in days back from now, or null for all time.', type: 'integer', nullable: true))]
    public private(set) ?int $days = null;

    /** @var list<CardCount> */
    #[OAS\Property(schema: new OAS\Schema(description: 'Every card drawn, most often first (ties by name).', type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/CardCount')))]
    public private(set) array $cards = [];
}
