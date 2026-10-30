<?php

namespace Tarot\Exception;

use RuntimeException;

/**
 * The day already has its daily card. Carries that card's reading, so the
 * controller can answer 409 with the id the client opens instead.
 */
class DailyCardTakenException extends RuntimeException
{
    public function __construct(public readonly string $readingId)
    {
        parent::__construct('You have already drawn your daily card for this day.');
    }
}
