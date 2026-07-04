<?php

namespace Tarot\Structure;

use OpenApi\Attributes as OA;

/**
 * A user-submitted report that a specific card scan has an artefact/issue and
 * should be re-scanned. One row per (deck, card); {@see $report_count} tracks how
 * many times it has been reported. {@see $deck_name} is resolved from the decks
 * table for the admin listing and is not a stored column.
 *
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OA\Schema(description: 'A report that a card scan needs re-scanning.')]
class CardReport extends AbstractStructure
{
    #[OA\Property(type: 'integer')]
    public private(set) int $report_id = 0;

    #[OA\Property(type: 'integer')]
    public private(set) int $deck_id = 0;

    #[OA\Property(type: 'string', description: 'Resolved deck name (from the decks table).')]
    public private(set) string $deck_name = '';

    #[OA\Property(type: 'integer')]
    public private(set) int $card_id = 0;

    #[OA\Property(type: 'string')]
    public private(set) string $card_name = '';

    #[OA\Property(type: 'integer', description: 'How many times this card has been reported.')]
    public private(set) int $report_count = 0;

    #[OA\Property(type: 'string', nullable: true, description: 'When an admin marked it handled; null = open.')]
    public private(set) ?string $resolved_at = null;

    #[OA\Property(type: 'string')]
    public private(set) string $first_reported_at = '';

    #[OA\Property(type: 'string')]
    public private(set) string $last_reported_at = '';

    /** Whether an admin has marked this report handled. */
    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }
}
