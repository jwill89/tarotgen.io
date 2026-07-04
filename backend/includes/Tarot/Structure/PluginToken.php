<?php

namespace Tarot\Structure;

use OpenApi\Attributes as OA;

/**
 * A personal access token linking a plugin install to a user account — the
 * read-only projection shown in the "Connected Apps" list. The token hash is
 * never part of this shape.
 *
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes
 * (hydrated by PDO / the Data layer).
 */
#[OA\Schema(description: 'A linked-plugin personal access token (no secret value exposed).')]
class PluginToken extends AbstractStructure
{
    #[OA\Property(type: 'integer')]
    public private(set) int $id = 0;

    #[OA\Property(type: 'integer')]
    public private(set) int $user_id = 0;

    #[OA\Property(type: 'string')]
    public private(set) string $label = '';

    #[OA\Property(type: 'string')]
    public private(set) string $scope = 'account';

    #[OA\Property(type: 'string')]
    public private(set) string $created_at = '';

    #[OA\Property(type: 'string', nullable: true)]
    public private(set) ?string $last_used_at = null;

    #[OA\Property(type: 'string', nullable: true)]
    public private(set) ?string $expires_at = null;

    #[OA\Property(type: 'string', nullable: true)]
    public private(set) ?string $revoked_at = null;

    /** Whether this token is currently usable (not revoked). */
    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
