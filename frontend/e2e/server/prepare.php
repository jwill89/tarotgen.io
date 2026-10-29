<?php

/**
 * Builds what the Playwright smoke run (playwright.config.ts) serves, before its
 * server starts:
 *
 * - a throwaway data directory (TAROT_DATA_DIR): a .env and a database made from
 *   backend/db/schema.sql, seeded with one deck system, one usable deck, one
 *   spread and an admin account whose random password goes into e2e.json beside
 *   it for the spec to read;
 * - backend/dist: a copy of the built SPA, where og.php looks for the app shell
 *   (in the deployed web root, dist/ sits beside og.php).
 *
 * Usage: php prepare.php <data-dir> <base-url>
 */

declare(strict_types=1);

use Tarot\Data\DeckData;
use Tarot\Data\DeckSystemData;
use Tarot\Data\SpreadData;
use Tarot\Data\UserData;
use Tarot\Repository\UserRepository;

$frontend = dirname(__DIR__, 2);
$backend = dirname($frontend) . '/backend';
require $backend . '/vendor/autoload.php';

[, $dataDir, $baseUrl] = $argv + [null, '', ''];
if ($dataDir === '' || $baseUrl === '') {
    fwrite(STDERR, "Usage: php prepare.php <data-dir> <base-url>\n");
    exit(1);
}
if (!is_file($frontend . '/dist/index.html')) {
    fwrite(STDERR, "frontend/dist is missing: run `npm run build` first.\n");
    exit(1);
}

removeTree($dataDir);
mkdir($dataDir . '/db', 0o777, true);
// Development, so the session cookie isn't Secure-only over plain http.
file_put_contents($dataDir . '/.env', "APP_ENV=development\nAPP_URL={$baseUrl}\n");

$db = new PDO('sqlite:' . $dataDir . '/db/tarotdb.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec((string)file_get_contents($backend . '/db/schema.sql'));

$system = new DeckSystemData($db)->store(['name' => 'Rider-Waite-Smith', 'short_name' => 'RWS', 'total_cards' => 78, 'approved' => true]);
if ($system === null) {
    throw new RuntimeException('Could not seed the deck system.');
}
new DeckSystemData($db)->storeCards($system->deck_system_id, cardNames());
new DeckData($db)->store(['name' => 'Smoke Test Deck', 'artist' => 'E2E', 'deck_system_id' => $system->deck_system_id, 'approved' => true, 'usable' => true]);
new SpreadData($db)->store([
    'name' => 'Past, Present, Future',
    'positions' => [
        ['order' => 1, 'title' => 'Past', 'x' => 25, 'y' => 50, 'rotation' => 0],
        ['order' => 2, 'title' => 'Present', 'x' => 50, 'y' => 50, 'rotation' => 0],
        ['order' => 3, 'title' => 'Future', 'x' => 75, 'y' => 50, 'rotation' => 0],
    ],
]);

$users = new UserRepository(new UserData($db));
$password = bin2hex(random_bytes(12));
$admin = $users->create('e2e-admin@tarotgen.test', 'E2E Admin', password_hash($password, PASSWORD_DEFAULT));
if ($admin === null) {
    throw new RuntimeException('Could not seed the admin account.');
}
$users->activate($admin->user_id);
$users->setAdmin($admin->user_id, true);
file_put_contents($dataDir . '/e2e.json', json_encode(['email' => $admin->email, 'password' => $password], JSON_THROW_ON_ERROR));

removeTree($backend . '/dist');
copyTree($frontend . '/dist', $backend . '/dist');

echo "E2E data in {$dataDir}\n";

/** @return list<array{card_id: int, name: string}> */
function cardNames(): array
{
    $names = [
        'The Fool', 'The Magician', 'The High Priestess', 'The Empress', 'The Emperor', 'The Hierophant',
        'The Lovers', 'The Chariot', 'Strength', 'The Hermit', 'Wheel of Fortune', 'Justice', 'The Hanged Man',
        'Death', 'Temperance', 'The Devil', 'The Tower', 'The Star', 'The Moon', 'The Sun', 'Judgement', 'The World',
    ];
    $ranks = ['Ace', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Page', 'Knight', 'Queen', 'King'];
    foreach (['Wands', 'Cups', 'Swords', 'Pentacles'] as $suit) {
        foreach ($ranks as $rank) {
            $names[] = "{$rank} of {$suit}";
        }
    }

    return array_map(static fn(int $i): array => ['card_id' => $i + 1, 'name' => $names[$i]], array_keys($names));
}

function removeTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($path);
}

function copyTree(string $from, string $to): void
{
    mkdir($to, 0o777, true);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
        $target = $to . substr($entry->getPathname(), strlen($from));
        $entry->isDir() ? mkdir($target) : copy($entry->getPathname(), $target);
    }
}
