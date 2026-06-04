<?php

namespace Tarot\Data;

use PDO;
use Tarot\Structure\User;

class UserData extends AbstractData
{
    /**
     * Every account, newest first — for the admin user-management screen.
     *
     * @return User[]
     */
    public function getAll(): array
    {
        $stmt = $this->db->query('SELECT * FROM users ORDER BY registered_at DESC, user_id DESC');
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findById(int $userId): ?User
    {
        $row = $this->row('SELECT * FROM users WHERE user_id = :id', [':id' => $userId]);
        return $row ? $this->hydrate($row) : null;
    }

    public function findByEmail(string $email): ?User
    {
        // The email column is COLLATE NOCASE, so this match is case-insensitive.
        $row = $this->row('SELECT * FROM users WHERE email = :email', [':email' => $email]);
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Raw auth row (including the password hash) for credential verification.
     * Kept separate from the hydrated User so the hash never reaches callers
     * that serialize the user.
     *
     * @return array{user_id:int,password_hash:string,is_active:int}|null
     */
    public function findAuthByEmail(string $email): ?array
    {
        $row = $this->row(
            'SELECT user_id, password_hash, is_active FROM users WHERE email = :email',
            [':email' => $email]
        );

        return $row ?: null;
    }

    public function emailExists(string $email): bool
    {
        return $this->row('SELECT 1 FROM users WHERE email = :email', [':email' => $email]) !== null;
    }

    public function displayNameExists(string $displayName): bool
    {
        return $this->row('SELECT 1 FROM users WHERE display_name = :name', [':name' => $displayName]) !== null;
    }

    public function create(string $email, string $displayName, string $passwordHash): ?User
    {
        $sql = 'INSERT INTO users (email, password_hash, display_name, is_active, is_admin, registered_at)
                VALUES (:email, :hash, :name, 0, 0, :now)';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':email' => $email,
            ':hash'  => $passwordHash,
            ':name'  => $displayName,
            ':now'   => date('Y-m-d H:i:s'),
        ]);

        return $this->findById((int)$this->db->lastInsertId());
    }

    public function activate(int $userId): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET is_active = 1 WHERE user_id = :id');
        return $stmt->execute([':id' => $userId]);
    }

    public function setAdmin(int $userId, bool $isAdmin): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET is_admin = :admin WHERE user_id = :id');
        return $stmt->execute([':admin' => $isAdmin ? 1 : 0, ':id' => $userId]);
    }

    public function touchLogin(int $userId): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET last_login_at = :now WHERE user_id = :id');
        return $stmt->execute([':now' => date('Y-m-d H:i:s'), ':id' => $userId]);
    }

    public function updatePasswordHash(int $userId, string $passwordHash): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET password_hash = :hash WHERE user_id = :id');
        return $stmt->execute([':hash' => $passwordHash, ':id' => $userId]);
    }

    public function updateDisplayName(int $userId, string $displayName): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET display_name = :name WHERE user_id = :id');
        return $stmt->execute([':name' => $displayName, ':id' => $userId]);
    }

    /** The stored password hash for a user, for credential re-verification. */
    public function getPasswordHash(int $userId): ?string
    {
        $stmt = $this->db->prepare('SELECT password_hash FROM users WHERE user_id = :id');
        $stmt->execute([':id' => $userId]);
        $hash = $stmt->fetchColumn();

        return is_string($hash) ? $hash : null;
    }

    public function delete(int $userId): bool
    {
        // user_tokens rows cascade via the FK (foreign_keys pragma is ON).
        $stmt = $this->db->prepare('DELETE FROM users WHERE user_id = :id');
        return $stmt->execute([':id' => $userId]);
    }

    // ── Google OAuth ─────────────────────────────────────────────

    public function findByGoogleId(string $googleId): ?User
    {
        $row = $this->row('SELECT * FROM users WHERE google_id = :gid', [':gid' => $googleId]);
        return $row ? $this->hydrate($row) : null;
    }

    public function setGoogleId(int $userId, ?string $googleId): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET google_id = :gid WHERE user_id = :id');
        return $stmt->execute([':gid' => $googleId, ':id' => $userId]);
    }

    public function googleIdExists(string $googleId): bool
    {
        return $this->row('SELECT 1 FROM users WHERE google_id = :gid', [':gid' => $googleId]) !== null;
    }

    /**
     * Create a user account from Google OAuth (no password, already active).
     */
    public function createFromGoogle(string $email, string $displayName, string $googleId): ?User
    {
        $sql = 'INSERT INTO users (email, password_hash, display_name, is_active, is_admin, registered_at, google_id)
                VALUES (:email, :hash, :name, 1, 0, :now, :gid)';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':email' => $email,
            ':hash'  => '', // No password — Google-only accounts log in via OAuth.
            ':name'  => $displayName,
            ':now'   => date('Y-m-d H:i:s'),
            ':gid'   => $googleId,
        ]);

        return $this->findById((int)$this->db->lastInsertId());
    }

    /** Fetch a single associative row, or null when there is no match. */
    private function row(string $sql, array $params): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function hydrate(array $row): User
    {
        return new User([
            'user_id'       => (int)$row['user_id'],
            'email'         => (string)$row['email'],
            'display_name'  => (string)$row['display_name'],
            'is_active'     => (bool)(int)$row['is_active'],
            'is_admin'      => (bool)(int)$row['is_admin'],
            'registered_at' => (string)$row['registered_at'],
            'last_login_at' => $row['last_login_at'] !== null ? (string)$row['last_login_at'] : null,
            'google_linked' => isset($row['google_id']) && $row['google_id'] !== null && $row['google_id'] !== '',
            'password_login_disabled' => (bool)(int)($row['password_login_disabled'] ?? 0),
            'has_passkeys'  => $this->userHasPasskeys((int)$row['user_id']),
        ]);
    }

    private function userHasPasskeys(int $userId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM user_passkeys WHERE user_id = :uid LIMIT 1');
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchColumn() !== false;
    }

    // ── Password Login Toggle ────────────────────────────────────────

    public function setPasswordLoginDisabled(int $userId, bool $disabled): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET password_login_disabled = :val WHERE user_id = :id');
        return $stmt->execute([':val' => $disabled ? 1 : 0, ':id' => $userId]);
    }

    public function isPasswordLoginDisabled(int $userId): bool
    {
        $stmt = $this->db->prepare('SELECT password_login_disabled FROM users WHERE user_id = :id');
        $stmt->execute([':id' => $userId]);
        $val = $stmt->fetchColumn();
        return (bool)(int)$val;
    }
}
