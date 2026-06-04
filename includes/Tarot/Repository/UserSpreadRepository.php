<?php

namespace Tarot\Repository;

use Tarot\Data\UserSpreadData;
use Tarot\Structure\UserSpread;

class UserSpreadRepository
{
    private UserSpreadData $data;

    public function __construct(UserSpreadData $data)
    {
        $this->data = $data;
    }

    /** List all of a user's personal spreads. */
    public function listByUser(int $userId): array
    {
        return $this->data->retrieve($userId);
    }

    /** Get a single user spread scoped to the owner. */
    public function get(int $userId, int $userSpreadId): ?UserSpread
    {
        $results = $this->data->retrieve($userId, $userSpreadId);
        return $results[0] ?? null;
    }

    /** Find a user spread by ID without user scoping (for reading generation). */
    public function findById(int $userSpreadId): ?UserSpread
    {
        return $this->data->findById($userSpreadId);
    }

    public function create(int $userId, array $data): ?UserSpread
    {
        return $this->data->store($userId, $data);
    }

    public function update(int $userId, int $userSpreadId, array $data): ?UserSpread
    {
        return $this->data->update($userId, $userSpreadId, $data);
    }

    public function delete(int $userId, int $userSpreadId): bool
    {
        return $this->data->delete($userId, $userSpreadId);
    }
}

