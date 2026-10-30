<?php

namespace Tarot\Structure;

use OpenApi\Spec as OAS;

/**
 * One day's daily card as the calendar shows it: the day, the reading it opens,
 * and its one card, worked out in SQL from the reading (DailyCardData). Extra
 * cards the owner drew into the reading later don't change it: it is the first.
 *
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OAS\Schema(description: "A day's daily card: the reading drawn for that day and the card it holds.")]
class DailyCard extends AbstractStructure
{
    #[OAS\Property(schema: new OAS\Schema(description: 'The day it was drawn for: the owner\'s local date, "Y-m-d".', type: 'string'))]
    public private(set) string $day = '';

    #[OAS\Property(schema: new OAS\Schema(description: 'The reading drawn for the day.', type: 'string'))]
    public private(set) string $reading_id = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $deck_id = 0;

    #[OAS\Property(schema: new OAS\Schema(description: 'The deck\'s name, or `Deck #<id>` once the deck is gone.', type: 'string'))]
    public private(set) string $deck_name = '';

    #[OAS\Property(schema: new OAS\Schema(description: "The card's number in its deck.", type: 'integer'))]
    public private(set) int $card_id = 0;

    #[OAS\Property(schema: new OAS\Schema(description: 'The card\'s name as the reading recorded it, or `Card #<id>` when the deck had none.', type: 'string'))]
    public private(set) string $card_name = '';

    #[OAS\Property(schema: new OAS\Schema(description: 'Drawn upside down.', type: 'boolean'))]
    public private(set) bool $reversed = false;
}
