<?php

namespace Tarot\Utility;

/**
 * Simple file-backed, IP-keyed rate limiter for public endpoints.
 *
 * Each limiter instance owns one JSON file in the system temp dir, keyed by a
 * caller-supplied namespace. Entries are { count, first } per key and reset once
 * the time window elapses. Suitable for low-volume abuse prevention (spam), not
 * a substitute for a real distributed limiter under heavy load.
 */
readonly class RateLimiter
{
    private string $namespace;
    private int $maxHits;
    private int $windowSeconds;

    public function __construct(string $namespace, int $maxHits, int $windowSeconds)
    {
        // Keep the namespace filesystem-safe.
        $this->namespace     = preg_replace('/[^A-Za-z0-9_]/', '_', $namespace) ?: 'default';
        $this->maxHits       = max(1, $maxHits);
        $this->windowSeconds = max(1, $windowSeconds);
    }

    /** True when $key has reached the limit within the current window. */
    public function isLimited(string $key): bool
    {
        $entry = $this->read()[$key] ?? null;

        if ($entry === null) {
            return false;
        }

        if (time() - (int)$entry['first'] > $this->windowSeconds) {
            return false; // window expired
        }

        return (int)$entry['count'] >= $this->maxHits;
    }

    /** Record one hit against $key, starting a fresh window if needed. */
    public function hit(string $key): void
    {
        $all   = $this->read();
        $now   = time();
        $entry = $all[$key] ?? null;

        if ($entry === null || $now - (int)$entry['first'] > $this->windowSeconds) {
            $all[$key] = ['count' => 1, 'first' => $now];
        } else {
            $all[$key]['count']++;
        }

        $this->write($all);
    }

    public function clear(string $key): void
    {
        $all = $this->read();
        unset($all[$key]);
        $this->write($all);
    }

    private function file(): string
    {
        return sys_get_temp_dir() . '/tarot_rl_' . $this->namespace . '.json';
    }

    /** @return array<string,array{count:int,first:int}> */
    private function read(): array
    {
        $file = $this->file();
        if (!is_file($file)) {
            return [];
        }

        $decoded = json_decode((string)file_get_contents($file), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function write(array $data): void
    {
        file_put_contents($this->file(), json_encode($data), LOCK_EX);
    }
}
