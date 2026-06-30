<?php

namespace Tarot\Structure;

/**
 * A user account, in its *safe* shape: the password hash is deliberately not a
 * property here, so it can never leak through jsonSerialize(). The Data layer
 * reads the hash separately (findAuthByEmail) only where authentication needs it.
 *
 * Properties use asymmetric visibility (PHP 8.4): reads are public
 * (e.g. $user->user_id), while writes are private — PDO's FETCH_CLASS and the
 * array constructor still hydrate rows, but callers cannot mutate the entity.
 */
class User extends AbstractStructure
{
    public private(set) int $user_id = 0;
    public private(set) string $email = '';
    public private(set) string $display_name = '';
    public private(set) bool $is_active = false;
    public private(set) bool $is_admin = false;
    public private(set) string $registered_at = '';
    public private(set) ?string $last_login_at = null;
    public private(set) bool $google_linked = false;
    public private(set) bool $password_login_disabled = false;
    public private(set) bool $has_passkeys = false;
}
