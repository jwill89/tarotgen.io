<?php

namespace Tarot\Structure;

use OpenApi\Spec as OAS;

/**
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OAS\Schema(description: 'A heading the public spread library groups spreads under. Admins keep the list; every public spread is in exactly one.')]
class SpreadCategory extends AbstractStructure
{
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $category_id = 0;

    #[OAS\Property(schema: new OAS\Schema(type: 'string', maxLength: 60))]
    public private(set) string $name = '';

    /** The list's order; the first category is where a spread goes when none is chosen. */
    #[OAS\Property(schema: new OAS\Schema(description: 'Its place in the list, from 1. The first category is the default for a new or approved spread.', type: 'integer'))]
    public private(set) int $sort_order = 0;

    #[OAS\Property(schema: new OAS\Schema(description: 'How many public spreads are in it.', type: 'integer'))]
    public private(set) int $spread_count = 0;
}
