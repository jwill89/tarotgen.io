<?php

namespace Tarot\Exception;

use RuntimeException;

/**
 * The day already has its daily card. Carries that card's reading, so the
 * controller can answer 409 with the id the client opens instead — or null
 * when the owner deleted that reading: the day stays drawn all the same.
 */
class DailyCardTakenException extends RuntimeException
{
    public function __construct(public readonly ?string $readingId)
    {
        parent::__construct($readingId === null
            ? 'You have already drawn your daily card for this day, and its reading was deleted.'
            : 'You have already drawn your daily card for this day.');
    }
}
