<?php

namespace Tarot\Repository;

use Tarot\Data\DeckSystemData;
use Tarot\Structure\DeckSystem;

class DeckSystemRepository
{
    private DeckSystemData $data;

    public function __construct(DeckSystemData $data)
    {
        $this->data = $data;
    }

    public function get(?int $id = null): array|DeckSystem
    {
        $results = $this->data->retrieve($id);

        if ($id !== null && count($results) > 0) {
            return $results[0];
        }

        if ($id !== null) {
            return $results; // empty array
        }

        return $results;
    }

    public function getApproved(): array
    {
        return $this->data->retrieveApproved();
    }

    public function getPending(): array
    {
        return $this->data->retrievePending();
    }

    public function create(array $data): ?DeckSystem
    {
        return $this->data->store($data);
    }

    public function update(int $id, array $data): ?DeckSystem
    {
        return $this->data->update($id, $data);
    }

    public function delete(int $id): bool
    {
        return $this->data->delete($id);
    }

    // ── Cards ────────────────────────────────────────────────────

    public function getCards(int $systemId): array
    {
        return $this->data->retrieveCards($systemId);
    }

    public function getCardsByIds(int $systemId, array $cardIds): array
    {
        return $this->data->retrieveCardsByIds($systemId, $cardIds);
    }

    public function saveCards(int $systemId, array $cards): void
    {
        $this->data->storeCards($systemId, $cards);
    }

    public function deleteCards(int $systemId): bool
    {
        return $this->data->deleteCards($systemId);
    }
}

