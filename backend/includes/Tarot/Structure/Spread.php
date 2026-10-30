<?php

namespace Tarot\Structure;

use OpenApi\Spec as OAS;

/**
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OAS\Schema(description: 'A predefined tarot spread layout.')]
class Spread extends AbstractStructure
{
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $spread_id = 0;

    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $name = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $description = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $card_count = 1;

    #[OAS\Property(schema: new OAS\Schema(description: 'The library category it is listed under.', type: 'integer'))]
    public private(set) int $category_id = 0;

    /** Populated by the JOIN — its category's name, so the library needs no second request. */
    #[OAS\Property(schema: new OAS\Schema(description: "Its category's name.", type: 'string'))]
    public private(set) string $category_name = '';

    /** Populated by the JOIN — its category's place in the admins' list. */
    #[OAS\Property(schema: new OAS\Schema(description: "Its category's place in the list, from 1: the library and the spread pickers show the categories in this order.", type: 'integer'))]
    public private(set) int $category_sort_order = 0;

    /**
     * Array of position objects, each shaped:
     * ['order' => int, 'title' => string, 'x' => float, 'y' => float, 'rotation' => int,
     *  'description' => string (only when it has one)]
     *
     * @var list<array<string,mixed>>
     */
    #[OAS\Property(schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/SpreadPosition')))]
    public private(set) array $positions = [] {
        set(array|string $value) => self::jsonList($value);
    }
}
