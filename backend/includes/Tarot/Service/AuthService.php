<?php

namespace Tarot\Service;

use Tarot\Config\Env;
use Tarot\Repository\UserRepository;
use Tarot\Repository\UserTokenRepository;
use Tarot\Structure\User;

/**
 * Account lifecycle: registration (+ activation token/email), activation, and
 * password login. Keeps all the rules in one place so the controller stays a
 * thin HTTP adapter.
 */
class AuthService
{
    public const string TOKEN_ACTIVATION = 'activation';
    public const string TOKEN_PASSWORD_RESET = 'password_reset';

    /** Activation links are valid for 24 hours. */
    private const int ACTIVATION_TTL = 86400;

    /** Password-reset links are valid for 1 hour (shorter, higher-stakes). */
    private const int RESET_TTL = 3600;

    private const int DISPLAY_NAME_MIN = 3;
    private const int DISPLAY_NAME_MAX = 30;

    public function __construct(
        private readonly UserRepository $users,
        private readonly UserTokenRepository $tokens,
        private readonly Mailer $mailer,
    ) {
    }

    /**
     * The password hashing algorithm: Argon2id when the PHP build supports it
     * (preferred), otherwise the platform default (bcrypt).
     */
    public static function passwordAlgo(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    /**
     * Register a new account. On success a user is created (inactive) and an
     * activation email is sent.
     *
     * @return array{
     *   ok: bool,
     *   errors?: string[],
     *   user?: User,
     *   emailed?: bool,
     *   activation_link?: string
     * }
     */
    public function register(string $email, string $displayName, #[\SensitiveParameter] string $password): array
    {
        $email       = strtolower(trim($email));
        $displayName = trim($displayName);

        $errors = $this->validateRegistration($email, $displayName, $password);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        // Uniqueness checks (columns are COLLATE NOCASE, so case-insensitive).
        if ($this->users->emailExists($email)) {
            $errors[] = 'An account with that email address already exists.';
        }
        if ($this->users->displayNameExists($displayName)) {
            $errors[] = 'That display name is already taken.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $hash = password_hash($password, self::passwordAlgo());
        $user = $this->users->create($email, $displayName, $hash);

        if ($user === null) {
            return ['ok' => false, 'errors' => ['Could not create the account. Please try again.']];
        }

        $link    = $this->issueActivationLink($user->user_id);
        $emailed = $this->mailer->sendActivation($user->email, $user->display_name, $link);

        return [
            'ok'              => true,
            'user'            => $user,
            'emailed'         => $emailed,
            'activation_link' => $link,
        ];
    }

    /**
     * Activate an account from a raw token. Returns true when a matching,
     * unused, unexpired activation token was found and consumed.
     */
    public function activate(#[\SensitiveParameter] string $rawToken): bool
    {
        $rawToken = trim($rawToken);
        if ($rawToken === '') {
            return false;
        }

        $row = $this->tokens->findValid(self::hashToken($rawToken), self::TOKEN_ACTIVATION);
        if ($row === null) {
            return false;
        }

        $this->users->activate((int)$row['user_id']);
        $this->tokens->markUsed((int)$row['token_id']);

        return true;
    }

    /**
     * Re-issue and (re)send an activation email for an existing, not-yet-active
     * account. Used by the admin user-management screen.
     *
     * @return array{ok:bool, error?:string, emailed?:bool, activation_link?:string}
     */
    public function resendActivation(int $userId): array
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            return ['ok' => false, 'error' => 'User not found.'];
        }
        if ($user->is_active) {
            return ['ok' => false, 'error' => 'That account is already active.'];
        }

        $link    = $this->issueActivationLink($userId);
        $emailed = $this->mailer->sendActivation($user->email, $user->display_name, $link);

        return ['ok' => true, 'emailed' => $emailed, 'activation_link' => $link];
    }

    /**
     * Verify credentials. On success updates last_login and transparently
     * rehashes the password if the algorithm/params have changed.
     *
     * @return array{status:'ok'|'invalid'|'inactive'|'password_disabled', user?:User}
     */
    public function authenticate(string $email, #[\SensitiveParameter] string $password): array
    {
        $email = strtolower(trim($email));
        $auth  = $this->users->findAuthByEmail($email);

        if ($auth === null || !password_verify($password, (string)$auth['password_hash'])) {
            return ['status' => 'invalid'];
        }

        if ((int)$auth['is_active'] !== 1) {
            return ['status' => 'inactive'];
        }

        $userId = (int)$auth['user_id'];

        // Check if password login has been disabled for this account.
        if ($this->users->isPasswordLoginDisabled($userId)) {
            return ['status' => 'password_disabled'];
        }

        if (password_needs_rehash((string)$auth['password_hash'], self::passwordAlgo())) {
            $this->users->updatePasswordHash($userId, password_hash($password, self::passwordAlgo()));
        }

        $this->users->touchLogin($userId);

        $user = $this->users->findById($userId);
        if ($user === null) {
            // The row was verified moments ago; a null here means it vanished
            // mid-request (e.g. concurrent deletion). Treat as a failed login.
            return ['status' => 'invalid'];
        }

        return ['status' => 'ok', 'user' => $user];
    }

    /**
     * Begin a password reset: if the email belongs to an account, issue a reset
     * token and email the link. The caller responds identically whether or not
     * the account exists (no account enumeration); `exists` is for internal/dev
     * use only.
     *
     * @return array{ok:bool, exists:bool, emailed?:bool, reset_link?:string}
     */
    public function requestPasswordReset(string $email): array
    {
        $email = strtolower(trim($email));
        $user  = $this->users->findByEmail($email);

        if ($user === null) {
            return ['ok' => true, 'exists' => false];
        }

        $link    = $this->issueResetLink($user->user_id);
        $emailed = $this->mailer->sendPasswordReset($user->email, $user->display_name, $link);

        return ['ok' => true, 'exists' => true, 'emailed' => $emailed, 'reset_link' => $link];
    }

    /**
     * Complete a password reset from a raw token. The new password must satisfy
     * the policy; a weak password is reported without consuming the token so the
     * user can retry with the same link. On success the password is updated, the
     * token consumed, and — since clicking the emailed link proves control of the
     * inbox — the account is also marked active.
     *
     * @return array{status:'ok'|'invalid'|'weak', error?:string}
     */
    public function resetPassword(
        #[\SensitiveParameter] string $rawToken,
        #[\SensitiveParameter] string $newPassword,
    ): array {
        $rawToken = trim($rawToken);
        if ($rawToken === '') {
            return ['status' => 'invalid'];
        }

        $row = $this->tokens->findValid(self::hashToken($rawToken), self::TOKEN_PASSWORD_RESET);
        if ($row === null) {
            return ['status' => 'invalid'];
        }

        $pwError = PasswordPolicy::validate($newPassword);
        if ($pwError !== null) {
            return ['status' => 'weak', 'error' => $pwError];
        }

        $userId = (int)$row['user_id'];
        $this->users->updatePasswordHash($userId, password_hash($newPassword, self::passwordAlgo()));
        $this->tokens->markUsed((int)$row['token_id']);
        $this->users->activate($userId);

        return ['status' => 'ok'];
    }

    /**
     * Change a user's display name (enforcing the same rules and case-insensitive
     * uniqueness as registration).
     *
     * @return array{ok:bool, error?:string}
     */
    public function changeDisplayName(int $userId, string $displayName): array
    {
        $displayName = trim($displayName);

        $error = $this->validateDisplayName($displayName);
        if ($error !== null) {
            return ['ok' => false, 'error' => $error];
        }

        $current = $this->users->findById($userId);
        if ($current === null) {
            return ['ok' => false, 'error' => 'Account not found.'];
        }

        // Allow keeping the same name (e.g. a capitalisation change); otherwise
        // the name must be free.
        if (
            mb_strtolower($displayName) !== mb_strtolower($current->display_name)
            && $this->users->displayNameExists($displayName)
        ) {
            return ['ok' => false, 'error' => 'That display name is already taken.'];
        }

        $this->users->updateDisplayName($userId, $displayName);
        return ['ok' => true];
    }

    /**
     * Change a user's password after verifying their current one.
     *
     * @return array{ok:bool, error?:string}
     */
    public function changePassword(
        int $userId,
        #[\SensitiveParameter] string $currentPassword,
        #[\SensitiveParameter] string $newPassword,
    ): array {
        $hash = $this->users->getPasswordHash($userId);
        if ($hash === null || !password_verify($currentPassword, $hash)) {
            return ['ok' => false, 'error' => 'Your current password is incorrect.'];
        }

        $pwError = PasswordPolicy::validate($newPassword);
        if ($pwError !== null) {
            return ['ok' => false, 'error' => $pwError];
        }

        $this->users->updatePasswordHash($userId, password_hash($newPassword, self::passwordAlgo()));
        return ['ok' => true];
    }

    /**
     * Delete a user's own account after verifying their password. Their readings
     * are removed automatically by the ON DELETE CASCADE foreign key.
     *
     * @return array{ok:bool, error?:string}
     */
    public function deleteAccount(int $userId, #[\SensitiveParameter] string $password): array
    {
        $hash = $this->users->getPasswordHash($userId);
        if ($hash === null || !password_verify($password, $hash)) {
            return ['ok' => false, 'error' => 'Password is incorrect.'];
        }

        $this->users->delete($userId);
        return ['ok' => true];
    }

    /**
     * Create (and email) a fresh activation token for a user, replacing any
     * outstanding ones. Returns the activation link.
     */
    private function issueActivationLink(int $userId): string
    {
        $this->tokens->deleteForUserType($userId, self::TOKEN_ACTIVATION);

        $raw     = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + self::ACTIVATION_TTL);
        $this->tokens->store($userId, self::TOKEN_ACTIVATION, self::hashToken($raw), $expires);

        $base = rtrim((string)Env::get('APP_URL', 'https://tarotgen.io'), '/');
        return $base . '/activate?token=' . $raw;
    }

    /**
     * Create a fresh password-reset token for a user, replacing any outstanding
     * ones. Returns the reset link.
     */
    private function issueResetLink(int $userId): string
    {
        $this->tokens->deleteForUserType($userId, self::TOKEN_PASSWORD_RESET);

        $raw     = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + self::RESET_TTL);
        $this->tokens->store($userId, self::TOKEN_PASSWORD_RESET, self::hashToken($raw), $expires);

        $base = rtrim((string)Env::get('APP_URL', 'https://tarotgen.io'), '/');
        return $base . '/reset-password?token=' . $raw;
    }

    private static function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /** @return string[] */
    private function validateRegistration(string $email, string $displayName, string $password): array
    {
        $errors = [];

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Please enter a valid email address.';
        }

        $nameError = $this->validateDisplayName($displayName);
        if ($nameError !== null) {
            $errors[] = $nameError;
        }

        $pwError = PasswordPolicy::validate($password);
        if ($pwError !== null) {
            $errors[] = $pwError;
        }

        return $errors;
    }

    /** @return string|null  Null when valid, otherwise the reason it was rejected. */
    private function validateDisplayName(string $displayName): ?string
    {
        $len = mb_strlen($displayName);
        if ($len < self::DISPLAY_NAME_MIN || $len > self::DISPLAY_NAME_MAX) {
            return 'Display name must be between ' . self::DISPLAY_NAME_MIN
                . ' and ' . self::DISPLAY_NAME_MAX . ' characters.';
        }
        if (preg_match('/^[\p{L}\p{N} ._\-]+$/u', $displayName) !== 1) {
            return 'Display name may only contain letters, numbers, spaces, and . _ -';
        }
        return null;
    }
}
