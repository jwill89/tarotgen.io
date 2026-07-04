<?php

namespace Tarot\Repository;

use Tarot\Data\PluginAuthCodeData;

readonly class PluginAuthCodeRepository
{
    public function __construct(private PluginAuthCodeData $data)
    {
    }

    public function store(int $userId, string $codeHash, string $codeChallenge, string $scope, string $expiresAt): bool
    {
        return $this->data->store($userId, $codeHash, $codeChallenge, $scope, $expiresAt);
    }

    /** @return array{id:int,user_id:int,code_challenge:string,scope:string}|null */
    public function findActive(string $codeHash): ?array
    {
        return $this->data->findActive($codeHash);
    }

    public function markUsed(int $id): bool
    {
        return $this->data->markUsed($id);
    }
}
