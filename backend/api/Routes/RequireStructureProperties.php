<?php

namespace Routes;

use OpenApi\Analysis;
use OpenApi\Annotations as OA;
use OpenApi\Undefined;

/**
 * swagger-php processor, run by `composer docs` and OpenApiSpecTest: marks every
 * property of a `Tarot\Structure` schema as required. AbstractStructure's
 * jsonSerialize() always sends all of them, but swagger-php leaves `required`
 * unset, which made every field of the generated frontend types optional
 * (AUDIT §4.4). A schema that lists its own `required` is left alone.
 */
final class RequireStructureProperties
{
    public function __invoke(Analysis $analysis): void
    {
        $components = $analysis->openapi?->components;
        if ($components === null || Undefined::isDefault($components) || Undefined::isDefault($components->schemas)) {
            return;
        }

        foreach ($components->schemas as $schema) {
            if (
                $schema->_context->namespace !== 'Tarot\Structure'
                || Undefined::isDefault($schema->properties)
                || !Undefined::isDefault($schema->required)
            ) {
                continue;
            }

            $schema->required = array_map(
                static fn(OA\Property $property): string => $property->property,
                $schema->properties
            );
        }
    }
}
