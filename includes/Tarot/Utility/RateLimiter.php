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
        $this->mutate(function (array $all) use ($key): array {
            $now   = time();
            $entry = $all[$key] ?? null;

            if ($entry === null || $now - (int)$entry['first'] > $this->windowSeconds) {
                $all[$key] = ['count' => 1, 'first' => $now];
            } else {
                $all[$key]['count']++;
            }

            return $all;
        });
    }

    public function clear(string $key): void
    {
        $this->mutate(static function (array $all) use ($key): array {
            unset($all[$key]);
            return $all;
        });
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

    /**
     * Atomically read → prune expired entries → apply $change → write back,
     * holding an exclusive lock for the whole read-modify-write so concurrent
     * requests can't lose increments. Pruning on every mutation stops the file
     * from growing without bound as new keys (IPs) accumulate over time.
     *
     * @param callable(array<string,array{count:int,first:int}>):array<string,array{count:int,first:int}> $change
     */
    private function mutate(callable $change): void
    {
        $fp = @fopen($this->file(), 'c+');
        if ($fp === false) {
            return; // can't open temp storage — fail open rather than block users
        }

        try {
            flock($fp, LOCK_EX);

            $contents = stream_get_contents($fp);
            $all      = json_decode((string)$contents, true);
            $all      = is_array($all) ? $all : [];

            $all = $change($this->prune($all));

            $json = json_encode($all);
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $json === false ? '{}' : $json);
            fflush($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Drop entries whose window has fully elapsed. A namespace file is only ever
     * written by limiters sharing the same window, so this is safe.
     *
     * @param  array<string,array{count:int,first:int}> $all
     * @return array<string,array{count:int,first:int}>
     */
    private function prune(array $all): array
    {
        $now = time();
        foreach ($all as $key => $entry) {
            if (!is_array($entry) || $now - (int)($entry['first'] ?? 0) > $this->windowSeconds) {
                unset($all[$key]);
            }
        }
        return $all;
    }
}
