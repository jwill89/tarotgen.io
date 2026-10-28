<?php

/**
 * The HTML entry point for every page route (see .htaccess). Serves the built
 * app shell, dist/index.html, with the two things only a server can add:
 *
 * - A Content-Security-Policy carrying a fresh nonce per response — Cloudflare
 *   needs it to inject its scripts under a strict policy (see
 *   Tarot\Utility\ContentSecurityPolicy). Hence PHP for every page, not just
 *   shares; the shell is never cached anyway.
 * - For /reading/{id}, social meta: the SPA can't set Open Graph tags for
 *   crawlers, so the generic <title> and og:/twitter: tags are swapped for
 *   reading-specific ones. The SPA still hydrates as normal for visitors.
 *
 * Every other page gets the shell exactly as built. (Named og.php for its
 * original, reading-only job.)
 */

require_once __DIR__ . '/vendor/autoload.php';

use Tarot\Config\Env;
use Tarot\Config\Paths;
use Tarot\Database\Connection;
use Tarot\Utility\ContentSecurityPolicy;

$template = @file_get_contents(__DIR__ . '/dist/index.html');
if ($template === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Application is not built.';
    exit;
}

Env::load(Paths::envFile());
$nonce = ContentSecurityPolicy::nonce();
header(ContentSecurityPolicy::headerName(Env::get('CSP_MODE')) . ': ' . ContentSecurityPolicy::forShell($nonce));

// Only a reading's own URL gets share meta (the rewrite leaves REQUEST_URI intact).
$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if (preg_match('#^/reading/([A-Za-z0-9]+)/?$#', $path, $m)) {
    $template = withReadingShareMeta($template, $m[1]);
}

header('Content-Type: text/html; charset=UTF-8');
echo $template;

/**
 * Swap the shell's generic title and social tags for the reading's. Falls back
 * to generic ones when the reading is missing or anything fails, and never
 * describes a password-protected reading.
 */
function withReadingShareMeta(string $template, string $id): string
{
    $siteUrl     = 'https://tarotgen.io';
    $title       = "TarotGen.io Tarot Generator";
    $description = 'View this tarot reading on TarotGen.io.';
    $image       = $siteUrl . '/assets/share_banner.png';
    $imageFile   = __DIR__ . '/assets/share_banner.png';
    $url         = $siteUrl . '/reading/' . $id;

    try {
        $db = Connection::getInstance();

        $stmt = $db->prepare('SELECT reading_info, reading_name, password_hash FROM readings WHERE reading_id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row !== false && !empty($row['password_hash'])) {
            // Don't leak details of a password-protected reading to crawlers.
            $title       = ($row['reading_name'] ?? '') !== '' ? (string)$row['reading_name'] : 'Protected Tarot Reading';
            $description = 'This reading is password-protected on TarotGen.io.';
        } elseif ($row !== false) {
            $info   = json_decode((string)$row['reading_info'], true) ?: [];
            $deckId = (int)($info['deck_id'] ?? 0);
            $count  = is_array($info['draw'] ?? null) ? count($info['draw']) : 0;
            $spread = $info['spread']['name'] ?? null;

            $deckName = '';
            if ($deckId > 0) {
                $ds = $db->prepare('SELECT name FROM decks WHERE deck_id = :d');
                $ds->execute([':d' => $deckId]);
                $deckName = (string)($ds->fetchColumn() ?: '');
            }

            // A custom reading name wins; otherwise describe the spread / card count.
            $title = ($row['reading_name'] ?? '') !== ''
                ? (string)$row['reading_name']
                : ($spread
                    ? ($spread . ' — Tarot Reading')
                    : ($count > 0 ? ($count . '-Card Tarot Reading') : 'Tarot Reading'));

            $description = ($deckName !== '' ? $deckName . ' · ' : '')
                . 'A tarot reading on TarotGen.io.';

            // Prefer the deck's card back as the share image, when it exists.
            $back = __DIR__ . '/assets/decks/' . $deckId . '/Card_Back.png';
            if ($deckId > 0 && is_file($back)) {
                $image     = $siteUrl . '/assets/decks/' . $deckId . '/Card_Back.png';
                $imageFile = $back;
            }
        }
    } catch (Throwable) {
        // Keep the defaults on any failure.
    }

    // Cache-bust the share image with the file's mtime so social crawlers (which
    // cache OG images hard, keyed by URL) refetch whenever the image changes.
    $mtime = @filemtime($imageFile);
    if ($mtime !== false) {
        $image .= '?v=' . $mtime;
    }

    $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    // Strip the build's generic title + managed social tags, then inject our own.
    $template = preg_replace('#<title>.*?</title>#is', '', $template, 1) ?? $template;
    $template = preg_replace(
        '#[ \t]*<meta[^>]*(?:property="(?:og|twitter):[^"]*"|name="(?:description|title)")[^>]*>\s*#i',
        '',
        $template
    ) ?? $template;
    // Drop the static canonical so we can point it at the reading itself.
    $template = preg_replace('#[ \t]*<link[^>]*rel="canonical"[^>]*>\s*#i', '', $template) ?? $template;

    $meta = '<title>' . $esc($title) . '</title>' . "\n"
        // Individual readings are shareable but shouldn't clutter search results.
        . '    <meta name="robots" content="noindex, follow" />' . "\n"
        . '    <link rel="canonical" href="' . $esc($url) . '" />' . "\n"
        . '    <meta name="title" content="' . $esc($title) . '" />' . "\n"
        . '    <meta name="description" content="' . $esc($description) . '" />' . "\n"
        . '    <meta property="og:type" content="article" />' . "\n"
        . '    <meta property="og:url" content="' . $esc($url) . '" />' . "\n"
        . '    <meta property="og:title" content="' . $esc($title) . '" />' . "\n"
        . '    <meta property="og:description" content="' . $esc($description) . '" />' . "\n"
        . '    <meta property="og:image" content="' . $esc($image) . '" />' . "\n"
        . '    <meta property="twitter:card" content="summary_large_image" />' . "\n"
        . '    <meta property="twitter:url" content="' . $esc($url) . '" />' . "\n"
        . '    <meta property="twitter:title" content="' . $esc($title) . '" />' . "\n"
        . '    <meta property="twitter:description" content="' . $esc($description) . '" />' . "\n"
        . '    <meta property="twitter:image" content="' . $esc($image) . '" />' . "\n";

    return str_replace('</head>', $meta . '</head>', $template);
}
