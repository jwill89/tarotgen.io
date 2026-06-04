<?php

namespace Tarot\Utility;

use Tarot\Config\Env;
use Tarot\Database\Connection;

/**
 * Centralised session management. Handles starting, persisting ("remember me"),
 * sliding cookie refresh, and cleanup — all in one place so controllers and
 * middleware don't duplicate session logic.
 *
 * Sessions are stored in the database (DbSessionHandler), not on the host's
 * filesystem, so an external GC cron / neighbouring vhost can't prune active
 * sessions after ~24 minutes. We control expiry and garbage collection here.
 */
final class Session
{
    /** 30-day lifetime for "remember me" sessions. */
    public const int REMEMBER_ME_LIFETIME = 60 * 60 * 24 * 30;

    /**
     * Sliding idle lifetime for ordinary (non-"remember me") sessions. The
     * browser cookie is a session cookie that dies on browser close; this only
     * bounds how long an abandoned-but-still-open tab can be resumed. Refreshed
     * on every request, so active users are never logged out mid-session.
     */
    public const int DEFAULT_LIFETIME = 60 * 60 * 24; // 24 hours

    /**
     * Start (or resume) the session, automatically honoring a previously-set
     * "remember me" flag with a sliding cookie refresh.
     */
    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        // Re-enable PHP's probabilistic GC (disabled by default on Debian-based
        // hosts) so our DbSessionHandler::gc() sweeps expired rows on ~1% of
        // requests — no cron required. Strict mode makes PHP reject session ids
        // the store doesn't recognise (session-fixation defence).
        //
        // Note: session.gc_maxlifetime is intentionally NOT set. With a custom
        // save handler it is only passed as the (ignored) $max_lifetime arg to
        // gc(); the authoritative expiry lives in the sessions.expires column,
        // which read()/validateId()/gc() check directly.
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        ini_set('session.use_strict_mode', '1');

        // Persist sessions in the database rather than the host's shared
        // filesystem, which an external cron / other vhost can garbage-collect
        // out from under us. The handler owns expiry and GC.
        session_set_save_handler(new DbSessionHandler(Connection::getInstance()), true);

        session_start();

        if (!empty($_SESSION['remember_me'])) {
            self::applySlidingExpiration();
        }
    }

    /**
     * Regenerate the session ID (prevents fixation attacks on privilege change)
     * while ensuring the cookie is sent with the correct persistent lifetime
     * when $persistent is true.
     *
     * This replaces bare session_regenerate_id(true) calls — which would send
     * the cookie with PHP's default lifetime (0 = session cookie) causing the
     * browser to discard it on close.
     */
    public static function regenerate(bool $persistent = false): void
    {
        $lifetime = $persistent ? self::REMEMBER_ME_LIFETIME : 0;

        // Configure cookie params BEFORE regenerating so PHP's internal
        // Set-Cookie header already carries the correct expiry.
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'httponly'  => true,
            'samesite' => 'Lax',
            'secure'   => Env::isProduction(),
        ]);

        session_regenerate_id(true);

        // Belt-and-suspenders: also send our own cookie to guarantee the
        // correct attributes reach the browser (overrides any stale header).
        if ($persistent) {
            self::sendCookie($lifetime);
        }
    }

    /**
     * Mark the current session for 30-day persistence ("remember me"). Call
     * this AFTER start() and regenerate().
     */
    public static function persist(): void
    {
        $_SESSION['remember_me'] = true;

        // The DB row's TTL is derived from $_SESSION['remember_me'] (see
        // DbSessionHandler::lifetime), so flagging it above is enough; here we
        // just extend the browser cookie to match the 30-day window.
        self::sendCookie(self::REMEMBER_ME_LIFETIME);
    }

    /**
     * Get the currently authenticated user ID from the session, or null.
     */
    public static function userId(): ?int
    {
        $id = (int)($_SESSION['user_id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /**
     * Destroy the user authentication state without ending the entire session
     * (preserves any other session data like admin flags if needed).
     */
    public static function clearUser(): void
    {
        unset($_SESSION['user_id'], $_SESSION['remember_me']);
    }

    /**
     * Apply sliding expiration: re-send the "remember me" cookie so the browser
     * keeps it while the user is actively using the site. (The DB row's expiry
     * slides independently on every request via the save handler.)
     */
    private static function applySlidingExpiration(): void
    {
        self::sendCookie(self::REMEMBER_ME_LIFETIME);
    }

    /**
     * (Re-)send the session cookie with the given lifetime.
     */
    private static function sendCookie(int $lifetime): void
    {
        setcookie(
            session_name(),
            session_id(),
            [
                'expires'  => time() + $lifetime,
                'path'     => '/',
                'httponly'  => true,
                'samesite' => 'Lax',
                'secure'   => Env::isProduction(),
            ]
        );
    }
}
