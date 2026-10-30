<?php

namespace Tarot\Structure;

use OpenApi\Spec as OAS;

/**
 * One of a user's reading tags and how many of their readings carry it: what
 * GET /account/tags lists for My Readings' tag filter and the tag autocomplete.
 *
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OAS\Schema(description: "A tag the signed-in user has given their readings, with how many carry it.")]
class ReadingTag extends AbstractStructure
{
    #[OAS\Property(schema: new OAS\Schema(description: 'The tag, as stored: lower-case.', type: 'string'))]
    public private(set) string $tag = '';

    #[OAS\Property(schema: new OAS\Schema(description: "How many of the user's readings carry it.", type: 'integer'))]
    public private(set) int $count = 0;
}
