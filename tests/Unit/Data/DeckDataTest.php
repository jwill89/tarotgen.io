<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Data;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Data\DeckData;
use Tarot\Structure\Deck;

/**
 * Focuses on the card-aspect handling (the recently-fixed clamp) and the
 * update() allow-list, against an in-memory SQLite database.
 */
#[CoversClass(DeckData::class)]
#[CoversClass(Deck::class)]
final class DeckDataTest extends TestCase
{
    private PDO $pdo;
    private DeckData $data;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Mirror the columns DeckData reads/writes (incl. migrate_deck_aspect).
        $this->pdo->exec(
            'CREATE TABLE deck_systems (
                deck_system_id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL DEFAULT \'\',
                short_name TEXT NOT NULL DEFAULT \'\',
                total_cards INTEGER NOT NULL DEFAULT 78,
                approved INTEGER NOT NULL DEFAULT 1,
                submitted_by INTEGER DEFAULT NULL
            )'
        );
        $this->pdo->exec("INSERT INTO deck_systems (name, short_name, total_cards) VALUES ('RWS', 'RWS', 78)");

        $this->pdo->exec(
            'CREATE TABLE decks (
                deck_id INTEGER PRIMARY KEY AUTOINCREMENT,
                deck_system_id INTEGER NOT NULL DEFAULT 1,
                name TEXT NOT NULL DEFAULT \'\',
                artist TEXT NOT NULL DEFAULT \'\',
                purchase_url TEXT NOT NULL DEFAULT \'\',
                additional_cards INTEGER NOT NULL DEFAULT 0,
                card_aspect_w REAL NOT NULL DEFAULT 5,
                card_aspect_h REAL NOT NULL DEFAULT 8.6,
                approved INTEGER NOT NULL DEFAULT 1,
                usable INTEGER NOT NULL DEFAULT 0,
                submitted_by INTEGER DEFAULT NULL
            )'
        );
        $this->pdo->exec('CREATE TABLE special_cards (deck_id INTEGER, card_id INTEGER)');

        $this->data = new DeckData($this->pdo);
    }

    public function testStorePreservesRealMillimetreAspectWithoutTruncating(): void
    {
        // Regression: the old clamp ceiling of 100 truncated a real card's mm
        // height (151.5 → 100), corrupting the ratio. It must round-trip intact.
        $deck = $this->data->store(['name' => 'Tall Deck', 'card_aspect_w' => 70.5, 'card_aspect_h' => 151.5]);

        $this->assertInstanceOf(Deck::class, $deck);
        $this->assertSame(70.5, $deck->getCardAspectW());
        $this->assertSame(151.5, $deck->getCardAspectH());
    }

    public function testStoreDefaultsAspectWhenOmitted(): void
    {
        $deck = $this->data->store(['name' => 'Default Deck']);

        $this->assertInstanceOf(Deck::class, $deck);
        $this->assertSame(5.0, $deck->getCardAspectW());
        $this->assertSame(8.6, $deck->getCardAspectH());
    }

    public function testUpdateClampsAspectToAPositiveSaneRange(): void
    {
        $deck = $this->data->store(['name' => 'Deck']);
        $id = $deck->getDeckId();

        // Non-positive floors to 0.1 (never 0 or negative → no "/ 0" ratio).
        $floored = $this->data->update($id, ['card_aspect_w' => 0, 'card_aspect_h' => -3]);
        $this->assertSame(0.1, $floored->getCardAspectW());
        $this->assertSame(0.1, $floored->getCardAspectH());

        // Absurdly large values cap at the generous ceiling rather than overflow.
        $capped = $this->data->update($id, ['card_aspect_w' => 999999, 'card_aspect_h' => 1e9]);
        $this->assertSame(100000.0, $capped->getCardAspectW());
        $this->assertSame(100000.0, $capped->getCardAspectH());

        // A realistic mm value in the middle is preserved exactly.
        $mm = $this->data->update($id, ['card_aspect_h' => 120]);
        $this->assertSame(120.0, $mm->getCardAspectH());
    }

    public function testUpdateOnlyWritesAllowListedColumns(): void
    {
        $deck = $this->data->store(['name' => 'Original', 'additional_cards' => 0]);
        $id = $deck->getDeckId();

        // 'name' and 'additional_cards' are allowed; 'submitted_by' and an unknown
        // column are not and must be silently ignored (not error).
        $updated = $this->data->update($id, [
            'name'             => 'Renamed',
            'additional_cards' => 5,
            'submitted_by'     => 999,     // not in the allow-list
            'evil_column'      => 'DROP',  // unknown
        ]);

        $this->assertInstanceOf(Deck::class, $updated);
        $this->assertSame('Renamed', $updated->getName());
        $this->assertSame(5, $updated->getAdditionalCards());
        // submitted_by stays NULL (was never set, and update can't touch it).
        $row = $this->pdo->query('SELECT submitted_by FROM decks WHERE deck_id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        $this->assertNull($row['submitted_by']);
    }

    public function testUpdateReturnsNullWhenNoAllowedFieldsProvided(): void
    {
        $deck = $this->data->store(['name' => 'Deck']);
        $result = $this->data->update($deck->getDeckId(), ['evil_column' => 'x']);
        $this->assertNull($result);
    }
}
