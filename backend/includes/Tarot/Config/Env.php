<?php

namespace Tarot\Config;

final class Env
{
    private static bool $loaded = false;

    /**
     * Load environment variables from a .env file.
     */
    public static function load(string $path): void
    {
        if (self::$loaded || !file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Strip surrounding quotes
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }

        self::$loaded = true;
    }

    /**
     * Retrieve an environment variable, or $default when it is unset or empty.
     *
     * The return type is conditional on $default: callers that pass a non-null
     * fallback are guaranteed a `string` back, so they don't need to re-coalesce
     * a possible null at every call site.
     *
     * @return ($default is null ? string|null : string)
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? getenv($key);

        // Treat unset (false) and empty string as "not provided".
        if ($value === false || $value === '') {
            return $default;
        }

        return (string)$value;
    }

    /**
     * Whether the application is running in production mode.
     */
    public static function isProduction(): bool
    {
        return self::get('APP_ENV') === 'production';
    }
}
