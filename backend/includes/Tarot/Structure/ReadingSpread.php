<?php

namespace Tarot\Structure;

use OpenApi\Spec as OAS;

/**
 * A spread a user's readings were laid out in, by the name the readings
 * recorded, and how many of them: what GET /account/reading-spreads lists for
 * My Readings' spread filter. Each name is one the filter finds (ReadingData
 * reads both from the same expression), so the list never offers a spread with
 * no readings.
 *
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OAS\Schema(description: "A spread the signed-in user's readings were laid out in, with how many.")]
class ReadingSpread extends AbstractStructure
{
    #[OAS\Property(schema: new OAS\Schema(description: 'The spread\'s name as the readings recorded it (personal spreads have no public id); "Free Draw" for free draws never placed. Send it as GET /account/readings?spread=.', type: 'string'))]
    public private(set) string $name = '';

    #[OAS\Property(schema: new OAS\Schema(description: "How many of the user's readings were laid out in it.", type: 'integer'))]
    public private(set) int $count = 0;
}
