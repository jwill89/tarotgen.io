<?php

namespace Tarot\Repository;

use Tarot\Data\PluginTokenData;
use Tarot\Structure\PluginToken;

readonly class PluginTokenRepository
{
    public function __construct(private PluginTokenData $data)
    {
    }

    public function store(int $userId, string $tokenHash, string $label, string $scope, ?string $expiresAt): int
    {
        return $this->data->store($userId, $tokenHash, $label, $scope, $expiresAt);
    }

    /** @return array{id:int,user_id:int}|null */
    public function findActive(string $tokenHash): ?array
    {
        return $this->data->findActive($tokenHash);
    }

    public function touchLastUsed(int $id): void
    {
        $this->data->touchLastUsed($id);
    }

    /** @return list<PluginToken> */
    public function listForUser(int $userId): array
    {
        return $this->data->listForUser($userId);
    }

    public function revoke(int $id, int $userId): bool
    {
        return $this->data->revoke($id, $userId);
    }
}
