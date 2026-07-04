<?php

namespace Tarot\Repository;

use Tarot\Data\PluginMessageData;
use Tarot\Structure\PluginMessage;

readonly class PluginMessageRepository
{
    public function __construct(private PluginMessageData $data)
    {
    }

    public function enqueue(
        int $recipientClientId,
        int $senderClientId,
        string $senderLabel,
        ?string $senderCharacter,
        ?string $senderWorld,
        string $type,
        string $payload,
        string $expiresAt
    ): int {
        return $this->data->enqueue(
            $recipientClientId,
            $senderClientId,
            $senderLabel,
            $senderCharacter,
            $senderWorld,
            $type,
            $payload,
            $expiresAt
        );
    }

    /** @return list<PluginMessage> */
    public function drain(int $recipientClientId): array
    {
        return $this->data->drain($recipientClientId);
    }

    public function distinctRecipientsSince(int $senderClientId, string $since): int
    {
        return $this->data->distinctRecipientsSince($senderClientId, $since);
    }

    public function sweepExpired(): void
    {
        $this->data->sweepExpired();
    }
}
