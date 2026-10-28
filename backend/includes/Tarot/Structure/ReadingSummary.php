<?php

namespace Tarot\Structure;

use OpenApi\Attributes as OA;

/**
 * One row of the admin readings list: who drew it, when, and with what, worked
 * out in SQL (ReadingData::listAll) rather than by decoding every reading here.
 *
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OA\Schema(description: 'A reading as the admin readings list shows it.')]
class ReadingSummary extends AbstractStructure
{
    #[OA\Property(type: 'string')]
    public private(set) string $reading_id = '';

    #[OA\Property(type: 'string')]
    public private(set) string $reading_time = '';

    #[OA\Property(type: 'integer', nullable: true, description: 'The owner, or null for a guest reading.')]
    public private(set) ?int $user_id = null;

    #[OA\Property(type: 'string', description: "The owner's display name, or `Guest`.")]
    public private(set) string $display_name = '';

    #[OA\Property(type: 'integer')]
    public private(set) int $deck_id = 0;

    #[OA\Property(type: 'string', description: 'The deck\'s name, or `Deck #<id>` once the deck is gone.')]
    public private(set) string $deck_name = '';

    #[OA\Property(type: 'string', description: 'The spread name, or empty for a free draw.')]
    public private(set) string $spread_name = '';
}
