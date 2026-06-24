<?php

namespace Tarot\Structure;

/**
 * A user account, in its *safe* shape: the password hash is deliberately not a
 * property here, so it can never leak through jsonSerialize(). The Data layer
 * reads the hash separately (findAuthByEmail) only where authentication needs it.
 */
class User extends AbstractStructure
{
    protected int $user_id = 0;
    protected string $email = '';
    protected string $display_name = '';
    protected bool $is_active = false;
    protected bool $is_admin = false;
    protected string $registered_at = '';
    protected ?string $last_login_at = null;
    protected bool $google_linked = false;
    protected bool $password_login_disabled = false;
    protected bool $has_passkeys = false;

    public function getUserId(): int
    {
        return $this->user_id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getDisplayName(): string
    {
        return $this->display_name;
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function isAdmin(): bool
    {
        return $this->is_admin;
    }

    public function getRegisteredAt(): string
    {
        return $this->registered_at;
    }

    public function getLastLoginAt(): ?string
    {
        return $this->last_login_at;
    }

    public function isGoogleLinked(): bool
    {
        return $this->google_linked;
    }

    public function isPasswordLoginDisabled(): bool
    {
        return $this->password_login_disabled;
    }

    public function hasPasskeys(): bool
    {
        return $this->has_passkeys;
    }
}
