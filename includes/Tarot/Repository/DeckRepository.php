<?php

namespace Tarot\Repository;

use Tarot\Data\DeckData;
use Tarot\Structure\Deck;

class DeckRepository
{
    private DeckData $data;

    public function __construct()
    {
        if (!isset($this->data)) {
            $this->data = new DeckData();
        }
    }

    public function get(int $deck_id = null): array|Deck
    {
        $results = $this->data->retrieve($deck_id);

        if ($deck_id !== null && count($results) > 0) {
            return $results[0];
        }

        return $results;
    }
}
