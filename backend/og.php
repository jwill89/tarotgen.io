<?php

/**
 * Server-rendered social meta for shared reading links.
 *
 * The SPA can't set Open Graph tags for crawlers, so requests to /reading/{id}
 * are routed here (see .htaccess). We output the built app shell exactly as
 * normal — so the SPA still hydrates for humans — but with the generic <title>
 * and og:/twitter: tags swapped for reading-specific ones.
 */

require_once __DIR__ . '/vendor/autoload.php';

use Tarot\Database\Connection;

$template = @file_get_contents(__DIR__ . '/dist/index.html');
if ($template === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Application is not built.';
    exit;
}

$siteUrl = 'https://tarotgen.io';

// Extract the reading id from the path (rewrite leaves REQUEST_URI intact).
$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$id = preg_match('#/reading/([A-Za-z0-9]+)#', $path, $m) ? $m[1] : '';

// Defaults (used when the reading is missing or anything fails).
$title       = "TarotGen.io Tarot Generator";
$description = 'View this tarot reading on TarotGen.io.';
$image       = $siteUrl . '/assets/share_banner.png';
$imageFile   = __DIR__ . '/assets/share_banner.png';
$url         = $siteUrl . ($id !== '' ? '/reading/' . $id : '/');

if ($id !== '') {
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

$template = str_replace('</head>', $meta . '</head>', $template);

header('Content-Type: text/html; charset=UTF-8');
echo $template;
