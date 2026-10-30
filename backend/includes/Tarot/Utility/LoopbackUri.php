<?php

namespace Tarot\Utility;

/**
 * The plugin's loopback redirect (RFC 8252): where the browser hands a one-time
 * code back to the listener the plugin opened on this machine. Both plugin flows
 * (the account link and the draw key) send a code only to such an address, so a
 * crafted link can't carry it off to another host.
 */
final class LoopbackUri
{
    /**
     * Whether a URL points at the local loopback interface over plain http.
     *
     * Matched as text, the way the SPA checks it, so the host is the one a browser
     * will visit: parse_url() reads `http://evil.example\@127.0.0.1/` as 127.0.0.1,
     * while a browser treats the backslash as a slash and goes to evil.example.
     */
    public static function isLoopback(string $url): bool
    {
        return preg_match('#^http://(?:127\.0\.0\.1|localhost|\[::1\])(?::\d+)?(?:/|\z)#i', $url) === 1;
    }

    /**
     * The redirect with $params appended to its query (it may already have one).
     *
     * @param array<string,string> $params
     */
    public static function withQuery(string $url, array $params): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
    }
}
