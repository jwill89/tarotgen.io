<?php

namespace Tarot\Structure;

use OpenApi\Attributes as OA;

/**
 * One queued share addressed to a recipient client — the projection returned
 * when the recipient drains its inbox. The passive in-game popup renders
 * {@see $sender_label} + {@see $type}; only an explicit "View" click ever
 * dereferences {@see $payload} (the shared reading's share code).
 *
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes
 * (hydrated by PDO / the Data layer).
 */
#[OA\Schema(description: 'A queued share delivered to a recipient plugin install.')]
class PluginMessage extends AbstractStructure
{
    #[OA\Property(type: 'integer')]
    public private(set) int $id = 0;

    #[OA\Property(type: 'string', description: 'Display name shown in the popup (the sender).')]
    public private(set) string $sender_label = '';

    #[OA\Property(type: 'integer', description: 'Sender routing id (for client-side block/report).')]
    public private(set) int $sender_client_id = 0;

    #[OA\Property(type: 'string', nullable: true, description: "Sender's self-disclosed character (for the party consent filter).")]
    public private(set) ?string $sender_character = null;

    #[OA\Property(type: 'string', nullable: true, description: "Sender's self-disclosed home world.")]
    public private(set) ?string $sender_world = null;

    #[OA\Property(type: 'string', description: 'Message kind; currently always "reading_share".')]
    public private(set) string $type = 'reading_share';

    #[OA\Property(type: 'string', description: 'The shared reading share code (dereferenced only on View).')]
    public private(set) string $payload = '';

    #[OA\Property(type: 'string')]
    public private(set) string $created_at = '';
}
