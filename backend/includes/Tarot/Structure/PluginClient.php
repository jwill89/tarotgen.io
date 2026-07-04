<?php

namespace Tarot\Structure;

use OpenApi\Attributes as OA;

/**
 * A plugin install's relay identity — the routing endpoint a share is delivered
 * to. Exists for every install (guest by default); {@see $user_id} is set only
 * once the install links a TarotGen account. Neither the token nor the recipient
 * character/world is ever exposed — the character/world is stored only as a keyed
 * hash so the server never holds a plaintext roster of players (see ShareService).
 *
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes
 * (hydrated by PDO / the Data layer).
 */
#[OA\Schema(description: "A plugin install's relay routing identity (no secret value exposed).")]
class PluginClient extends AbstractStructure
{
    #[OA\Property(type: 'integer')]
    public private(set) int $client_id = 0;

    #[OA\Property(type: 'integer', nullable: true, description: 'Linked account id, or null for a guest install.')]
    public private(set) ?int $user_id = null;

    #[OA\Property(type: 'string', description: 'Who may push a share: nobody | friends | party_or_friends | anyone.')]
    public private(set) string $accept_tier = 'party_or_friends';

    #[OA\Property(type: 'string', nullable: true)]
    public private(set) ?string $last_seen = null;

    #[OA\Property(type: 'string')]
    public private(set) string $created_at = '';

    #[OA\Property(type: 'string', nullable: true)]
    public private(set) ?string $revoked_at = null;

    /** Whether this client is currently usable (not revoked). */
    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /** Whether this install has linked a TarotGen account. */
    public function isLinked(): bool
    {
        return $this->user_id !== null;
    }
}
