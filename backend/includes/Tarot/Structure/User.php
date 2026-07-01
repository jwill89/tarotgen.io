<?php

namespace Tarot\Structure;

use OpenApi\Attributes as OA;

/**
 * A user account, in its *safe* shape: the password hash is deliberately not a
 * property here, so it can never leak through jsonSerialize(). The Data layer
 * reads the hash separately (findAuthByEmail) only where authentication needs it.
 *
 * Properties use asymmetric visibility (PHP 8.4): reads are public
 * (e.g. $user->user_id), while writes are private — PDO's FETCH_CLASS and the
 * array constructor still hydrate rows, but callers cannot mutate the entity.
 */
#[OA\Schema(description: 'A user account in its safe shape (no secrets or hashes).')]
class User extends AbstractStructure
{
    #[OA\Property(type: 'integer')]
    public private(set) int $user_id = 0;

    #[OA\Property(type: 'string')]
    public private(set) string $email = '';

    #[OA\Property(type: 'string')]
    public private(set) string $display_name = '';

    #[OA\Property(type: 'boolean')]
    public private(set) bool $is_active = false;

    #[OA\Property(type: 'boolean')]
    public private(set) bool $is_admin = false;

    #[OA\Property(type: 'string')]
    public private(set) string $registered_at = '';

    #[OA\Property(type: 'string', nullable: true)]
    public private(set) ?string $last_login_at = null;

    #[OA\Property(type: 'boolean')]
    public private(set) bool $google_linked = false;

    #[OA\Property(type: 'boolean')]
    public private(set) bool $password_login_disabled = false;

    #[OA\Property(type: 'boolean')]
    public private(set) bool $has_passkeys = false;
}
