<?php

namespace Tarot\Structure;

use OpenApi\Attributes as OA;

/**
 * A card users have reported as having a problem with its image. One row per
 * (deck, card); {@see $report_count} tracks how many times it has been reported,
 * and {@see $submissions} holds the reason each reporter gave. {@see $deck_name}
 * is resolved from the decks table for the admin listing and is not a stored
 * column.
 *
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OA\Schema(description: 'A card users have reported as having a problem with its image.')]
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

    /**
     * Starts at 1 and goes up each time the card is reported again after an admin
     * resolved it, so the reasons for the current problem can be told apart from
     * those that were already dealt with.
     */
    #[OA\Property(
        type: 'integer',
        description: 'Goes up each time the card is reported again after being resolved.'
    )]
    public private(set) int $round = 1;

    /**
     * Every reason given for this card, newest first. Reports made before reasons
     * were collected (or by an older plugin build) have no entry, so this can hold
     * fewer items than {@see $report_count}.
     *
     * @var list<CardReportSubmission>
     */
    #[OA\Property(
        type: 'array',
        items: new OA\Items(ref: CardReportSubmission::class),
        description: 'Each reason given, newest first. Older reports may have none.'
    )]
    public private(set) array $submissions = [];

    /** Whether an admin has marked this report handled. */
    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }
}
