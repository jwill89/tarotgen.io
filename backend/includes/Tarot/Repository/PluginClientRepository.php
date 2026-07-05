<?php

namespace Tarot\Repository;

use Tarot\Data\PluginClientData;
use Tarot\Structure\PluginClient;

readonly class PluginClientRepository
{
    public function __construct(private PluginClientData $data)
    {
    }

    public function create(string $tokenHash, ?int $userId): int
    {
        return $this->data->create($tokenHash, $userId);
    }

    /** @return array{client_id:int,user_id:int|null}|null */
    public function findActive(string $tokenHash): ?array
    {
        return $this->data->findActive($tokenHash);
    }

    /** @return array{client_id:int,accept_tier:string}|null */
    public function findByIdentity(string $identityHash): ?array
    {
        return $this->data->findByIdentity($identityHash);
    }

    public function findById(int $clientId): ?PluginClient
    {
        return $this->data->findById($clientId);
    }

    public function addIdentity(int $clientId, string $identityHash): void
    {
        $this->data->addIdentity($clientId, $identityHash);
    }

    public function removeIdentity(int $clientId, string $identityHash): void
    {
        $this->data->removeIdentity($clientId, $identityHash);
    }

    /** @param list<string> $identityHashes */
    public function syncIdentities(int $clientId, array $identityHashes): void
    {
        $this->data->syncIdentities($clientId, $identityHashes);
    }

    public function identityCount(int $clientId): int
    {
        return $this->data->identityCount($clientId);
    }

    public function setAcceptTier(int $clientId, string $tier): void
    {
        $this->data->setAcceptTier($clientId, $tier);
    }

    public function touchLastSeen(int $clientId): void
    {
        $this->data->touchLastSeen($clientId);
    }

    public function isBlocked(int $ownerClientId, int $blockedClientId): bool
    {
        return $this->data->isBlocked($ownerClientId, $blockedClientId);
    }

    public function block(int $ownerClientId, int $blockedClientId): void
    {
        $this->data->block($ownerClientId, $blockedClientId);
    }

    public function unblock(int $ownerClientId, int $blockedClientId): void
    {
        $this->data->unblock($ownerClientId, $blockedClientId);
    }
}
