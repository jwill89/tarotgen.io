<?php

namespace Tarot\Structure;

use OpenApi\Spec as OAS;

/**
 * How often one card turned up in a user's readings (a row of CardFrequency),
 * counted in SQL over each reading's draw (CardFrequencyData).
 *
 * Over all decks a card is its name, so "The Tower" counts once whichever deck
 * it came from; card_id and deck_id are then null. Within one deck it is the
 * deck's card. A card drawn before its deck named it is that deck's card either
 * way, labelled `Card #<id>`.
 *
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OAS\Schema(description: 'One card and how often it was drawn.')]
class CardCount extends AbstractStructure
{
    #[OAS\Property(schema: new OAS\Schema(description: 'The card\'s name as the readings recorded it, or `Card #<id>` for a card its deck had not named.', type: 'string'))]
    public private(set) string $card_name = '';

    #[OAS\Property(schema: new OAS\Schema(description: "The card's number in its deck; null when counted by name across decks.", type: 'integer', nullable: true))]
    public private(set) ?int $card_id = null;

    #[OAS\Property(schema: new OAS\Schema(description: 'The deck it was drawn from; null when counted by name across decks.', type: 'integer', nullable: true))]
    public private(set) ?int $deck_id = null;

    #[OAS\Property(schema: new OAS\Schema(description: 'Times drawn: upright + reversed.', type: 'integer'))]
    public private(set) int $count = 0;

    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $upright = 0;

    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $reversed = 0;
}
