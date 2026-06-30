<?php

namespace Tarot\Service;

/**
 * Password strength rules. Following modern (NIST 800-63B) guidance we favour
 * length over arbitrary composition rules: a long passphrase is encouraged and
 * accepted, while a short "complex" password is not. We only reject passwords
 * that are too short, absurdly long (to bound hashing cost / abuse), or on a
 * small blocklist of obvious choices.
 */
final class PasswordPolicy
{
    public const int MIN_LENGTH = 12;
    public const int MAX_LENGTH = 128;

    /** A few of the most common weak passwords that still pass a length check. */
    private const array BLOCKLIST = [
        'password', 'passw0rd', 'password123', 'password1234',
        '123456789012', '1234567890123', 'qwertyuiop12',
        'iloveyou1234', 'letmein12345', 'admin1234567',
        'tarotgenerator', 'changemechange',
    ];

    /**
     * @return string|null  Null when the password is acceptable, otherwise a
     *                      human-readable reason it was rejected.
     */
    public static function validate(#[\SensitiveParameter] string $password): ?string
    {
        $length = mb_strlen($password);

        if ($length < self::MIN_LENGTH) {
            return 'Password must be at least ' . self::MIN_LENGTH . ' characters long. A memorable passphrase works well.';
        }

        if ($length > self::MAX_LENGTH) {
            return 'Password must be ' . self::MAX_LENGTH . ' characters or fewer.';
        }

        if (trim($password) === '') {
            return 'Password cannot be only whitespace.';
        }

        // A single repeated character (e.g. "aaaaaaaaaaaa") is trivially weak.
        if (preg_match('/^(.)\1+$/u', $password)) {
            return 'Password is too simple. Please choose a less predictable password.';
        }

        if (in_array(mb_strtolower($password), self::BLOCKLIST, true)) {
            return 'That password is too common. Please choose a less predictable password.';
        }

        return null;
    }
}
