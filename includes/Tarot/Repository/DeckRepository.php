<?php

namespace Tarot\Repository;

use Tarot\Data\DeckData;
use Tarot\Structure\Deck;

class DeckRepository
{
    private DeckData $data;

    public function __construct(DeckData $data)
    {
        $this->data = $data;
    }

    public function get(?int $deck_id = null): array|Deck
    {
        $results = $this->data->retrieve($deck_id);

        if ($deck_id !== null && count($results) > 0) {
            return $results[0];
        }

        return $results;
    }

    /** Decks marked usable — shown in public deck selectors. */
    public function getUsable(): array
    {
        return $this->data->retrieveUsable();
    }

    /** Decks that are approved (admin-approved list). */
    public function getApproved(): array
    {
        return $this->data->retrieveApproved();
    }

    /** Decks that are not yet approved (pending submissions). */
    public function getPending(): array
    {
        return $this->data->retrievePending();
    }

    public function create(array $data): ?Deck
    {
        return $this->data->store($data);
    }

    public function update(int $deck_id, array $data): ?Deck
    {
        return $this->data->update($deck_id, $data);
    }

    public function delete(int $deck_id): bool
    {
        return $this->data->delete($deck_id);
    }
}
