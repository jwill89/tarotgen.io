<?php

namespace Tarot\Repository;

use Tarot\Data\CardReportData;
use Tarot\Structure\CardReport;

readonly class CardReportRepository
{
    public function __construct(private CardReportData $data)
    {
    }

    public function report(int $deckId, int $cardId, string $cardName): void
    {
        $this->data->report($deckId, $cardId, $cardName);
    }

    /** @return list<CardReport> */
    public function get(bool $includeResolved): array
    {
        return $this->data->retrieve($includeResolved);
    }

    public function setResolved(int $reportId, bool $resolved): bool
    {
        return $this->data->setResolved($reportId, $resolved);
    }
}
