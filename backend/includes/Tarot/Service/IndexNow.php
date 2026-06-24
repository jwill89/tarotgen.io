<?php

namespace Tarot\Service;

use Tarot\Config\Env;

/**
 * IndexNow submitter — pings participating search engines (Bing and Yandex,
 * and via Bing, DuckDuckGo) so they recrawl new/changed public URLs promptly
 * instead of waiting for the next scheduled crawl.
 *
 * Ownership is proven by a key file hosted at the site root (public/<key>.txt,
 * deployed to dist/ and served from /). The key value also lives in the
 * INDEXNOW_KEY environment variable. When no key is configured the submitter
 * degrades gracefully to a no-op, mirroring how Mailer behaves without SMTP.
 *
 * The HTTP POST is injectable so the submission logic can be unit-tested
 * without hitting the network.
 */
class IndexNow
{
    /** Shared endpoint; participating engines forward submissions to each other. */
    private const ENDPOINT = 'https://api.indexnow.org/indexnow';

    /** IndexNow accepts at most this many URLs per request. */
    private const MAX_URLS = 10000;

    /** @var callable(string, string): int Sends a JSON POST, returns the HTTP status. */
    private $poster;

    /**
     * @param (callable(string, string): int)|null $poster Override the network
     *        sender (endpoint, json body) => http status. Defaults to cURL.
     */
    public function __construct(?callable $poster = null)
    {
        $this->poster = $poster ?? self::curlPoster(...);
    }

    /** Whether an IndexNow key is configured (otherwise submit() is a no-op). */
    public function isConfigured(): bool
    {
        return $this->key() !== null;
    }

    /**
     * Submit one or more absolute URLs for (re)crawling. Returns true when the
     * endpoint accepts them (HTTP 200/202), false when unconfigured, given no
     * URLs, or on any failure.
     *
     * @param list<string> $urls
     */
    public function submit(array $urls): bool
    {
        $key  = $this->key();
        $urls = array_values(array_filter($urls, static fn (string $u): bool => $u !== ''));
        if ($key === null || $urls === []) {
            return false;
        }

        $host = parse_url($this->baseUrl(), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        // Every submitted URL must share the host declared here.
        $payload = json_encode([
            'host'        => $host,
            'key'         => $key,
            'keyLocation' => rtrim($this->baseUrl(), '/') . '/' . $key . '.txt',
            'urlList'     => array_slice($urls, 0, self::MAX_URLS),
        ], JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return false;
        }

        $status = ($this->poster)(self::ENDPOINT, $payload);

        return $status === 200 || $status === 202;
    }

    private function key(): ?string
    {
        return Env::get('INDEXNOW_KEY');
    }

    private function baseUrl(): string
    {
        return Env::get('APP_URL') ?? 'https://tarotgen.io';
    }

    /** Default network sender: a short-timeout JSON POST via cURL. */
    private static function curlPoster(string $endpoint, string $jsonBody): int
    {
        $ch = curl_init($endpoint);
        if ($ch === false) {
            return 0;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonBody,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);

        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $status;
    }
}
