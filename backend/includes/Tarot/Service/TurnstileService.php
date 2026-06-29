<?php

namespace Tarot\Service;

use Tarot\Config\Env;

/**
 * Cloudflare Turnstile (CAPTCHA) verification.
 *
 * Configuration comes from environment variables:
 *   CLOUDFLARE_TURNSTILE_SITEKEY  — public, embedded in the login widget
 *   CLOUDFLARE_TURNSTILE_SECRET   — private, used for server-side verification
 *
 * When the keys are absent the feature is simply "off" (see isConfigured), so
 * the app keeps working in environments where Turnstile isn't set up.
 */
class TurnstileService
{
    private const string VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** Turnstile is active only when both the public site key and secret exist. */
    public function isConfigured(): bool
    {
        return Env::get('CLOUDFLARE_TURNSTILE_SITEKEY') !== null
            && Env::get('CLOUDFLARE_TURNSTILE_SECRET') !== null;
    }

    /** The public site key for the frontend widget (null when unconfigured). */
    public function getSiteKey(): ?string
    {
        return Env::get('CLOUDFLARE_TURNSTILE_SITEKEY');
    }

    /**
     * Verify a Turnstile response token against Cloudflare.
     *
     * Returns true when Cloudflare confirms the token is valid. A blank token,
     * a missing secret, or any transport/parse failure returns false (fail
     * closed — a challenge that can't be verified is treated as not passed).
     *
     * @param string      $token    The cf-turnstile-response token from the client.
     * @param string|null $remoteIp The client IP, forwarded to Cloudflare for analytics.
     */
    public function verify(string $token, ?string $remoteIp = null): bool
    {
        $secret = Env::get('CLOUDFLARE_TURNSTILE_SECRET');
        if ($secret === null || trim($token) === '') {
            return false;
        }

        $payload = ['secret' => $secret, 'response' => $token];
        if ($remoteIp !== null && $remoteIp !== '' && $remoteIp !== 'unknown') {
            $payload['remoteip'] = $remoteIp;
        }

        $data = $this->postSiteVerify($payload);

        return is_array($data) && ($data['success'] ?? false) === true;
    }

    /**
     * POST to the Cloudflare siteverify endpoint and decode the JSON response.
     * Extracted so the network boundary can be substituted in tests.
     *
     * @param array<string,string> $payload
     * @return array<string,mixed>|null
     */
    protected function postSiteVerify(array $payload): ?array
    {
        $ch = curl_init(self::VERIFY_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !is_string($response)) {
            return null;
        }

        $data = json_decode($response, true);

        return is_array($data) ? $data : null;
    }
}
