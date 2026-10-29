<?php

namespace Tarot\Structure;

/**
 * What a password sign-in checks. The hash is private, so a caller can verify
 * against it but never read it, and json_encode() leaves it out. That is why
 * this is not an AbstractStructure: its jsonSerialize() reflects every
 * property, private ones included. The User entity never carries the hash.
 */
final class UserCredentials
{
    public private(set) int $user_id = 0;

    public private(set) bool $is_active = false;

    /** Written only by PDO's FETCH_CLASS (UserData::findAuthByEmail). */
    private string $password_hash = '';

    public function verify(#[\SensitiveParameter] string $password): bool
    {
        return password_verify($password, $this->password_hash);
    }

    /** False for an account made by signing in with Google, until a password is set. */
    public function hasPassword(): bool
    {
        return $this->password_hash !== '';
    }

    /** Whether the hash was made with older settings than $algo (rehash on sign-in). */
    public function needsRehash(string|int|null $algo): bool
    {
        return password_needs_rehash($this->password_hash, $algo);
    }
}
