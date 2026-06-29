<?php

namespace Tarot\Service;

use Tarot\Config\Env;

/**
 * Handles the Google OAuth2 server-side flow: building the authorization URL,
 * exchanging the authorization code for tokens, and fetching user info.
 *
 * Configuration comes from environment variables:
 *   GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, GOOGLE_REDIRECT_URI
 */
class GoogleOAuthService
{
    private const string AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const string TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const string USERINFO_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';

    public function isConfigured(): bool
    {
        return Env::get('GOOGLE_CLIENT_ID') !== null
            && Env::get('GOOGLE_CLIENT_SECRET') !== null
            && Env::get('GOOGLE_REDIRECT_URI') !== null;
    }

    public function getClientId(): ?string
    {
        return Env::get('GOOGLE_CLIENT_ID');
    }

    /**
     * Build the Google OAuth2 authorization URL.
     *
     * @param string $state An opaque, unguessable value to prevent CSRF.
     */
    public function buildAuthUrl(string $state): string
    {
        $params = [
            'client_id'     => Env::get('GOOGLE_CLIENT_ID'),
            'redirect_uri'  => Env::get('GOOGLE_REDIRECT_URI'),
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'access_type'   => 'online',
            'prompt'        => 'select_account',
        ];

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Exchange an authorization code for an access token.
     *
     * @return array{access_token:string,id_token:string}|null
     */
    public function exchangeCode(string $code): ?array
    {
        $payload = [
            'code'          => $code,
            'client_id'     => Env::get('GOOGLE_CLIENT_ID'),
            'client_secret' => Env::get('GOOGLE_CLIENT_SECRET'),
            'redirect_uri'  => Env::get('GOOGLE_REDIRECT_URI'),
            'grant_type'    => 'authorization_code',
        ];

        $ch = curl_init(self::TOKEN_URL);
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
        if (!is_array($data) || empty($data['access_token'])) {
            return null;
        }

        return $data;
    }

    /**
     * Fetch the authenticated user's profile from Google.
     *
     * The `email_verified` claim is included so callers can refuse to bind an
     * account to an unverified email (see GoogleAuthController::callback).
     *
     * @return array{sub:string,email:string,email_verified?:bool,name:string,picture:string}|null
     */
    public function fetchUserInfo(string $accessToken): ?array
    {
        $ch = curl_init(self::USERINFO_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$accessToken}"],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !is_string($response)) {
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || empty($data['sub']) || empty($data['email'])) {
            return null;
        }

        return $data;
    }
}
