<?php

/**
 * Submit public URLs to IndexNow (Bing, Yandex, and DuckDuckGo via Bing) so
 * they recrawl promptly. Run after a deploy, or whenever public content that
 * affects an indexable page changes.
 *
 * Requires INDEXNOW_KEY in .env and the matching key file at public/<key>.txt.
 *
 * Usage (from the project root):
 *   php scripts/indexnow.php                                   # submit every URL in public/sitemap.xml
 *   php scripts/indexnow.php https://tarotgen.io/changelog ... # submit specific URLs
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Tarot\Config\Env;
use Tarot\Service\IndexNow;

Env::load(__DIR__ . '/../.env');

$indexNow = new IndexNow();
if (!$indexNow->isConfigured()) {
    fwrite(STDERR, "INDEXNOW_KEY is not set in .env — nothing submitted.\n");
    exit(1);
}

/** @var list<string> $urls */
$urls = array_slice($argv, 1);
if ($urls === []) {
    $urls = sitemapUrls(__DIR__ . '/../public/sitemap.xml');
}

if ($urls === []) {
    fwrite(STDERR, "No URLs to submit (sitemap empty or unreadable).\n");
    exit(1);
}

echo 'Submitting ' . count($urls) . " URL(s) to IndexNow:\n";
foreach ($urls as $url) {
    echo "  {$url}\n";
}

if ($indexNow->submit($urls)) {
    echo "Done — participating search engines have been notified.\n";
    exit(0);
}

fwrite(STDERR, "IndexNow rejected the submission (is the key file reachable at the site root?).\n");
exit(1);

/**
 * Extract <loc> URLs from a sitemap.xml file.
 *
 * @return list<string>
 */
function sitemapUrls(string $path): array
{
    $xml = @simplexml_load_file($path);
    if ($xml === false) {
        return [];
    }

    $urls = [];
    foreach ($xml->url as $entry) {
        $loc = trim((string) $entry->loc);
        if ($loc !== '') {
            $urls[] = $loc;
        }
    }

    return $urls;
}
