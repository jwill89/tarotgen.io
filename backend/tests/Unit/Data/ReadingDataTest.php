<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Data;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Data\ReadingData;
use Tarot\Structure\Reading;

#[CoversClass(ReadingData::class)]
final class ReadingDataTest extends TestCase
{
    private PDO $pdo;
    private ReadingData $data;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE readings (
                reading_id TEXT PRIMARY KEY,
                reading_info TEXT NOT NULL,
                reading_time TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                user_id INTEGER DEFAULT NULL,
                hide_user INTEGER NOT NULL DEFAULT 0,
                reading_name TEXT DEFAULT NULL,
                reading_notes TEXT DEFAULT NULL,
                is_final INTEGER NOT NULL DEFAULT 0,
                password_hash TEXT DEFAULT NULL
            )'
        );
        // Read for Reading::$daily_card_day (as in db/schema.sql, minus users).
        $this->pdo->exec(
            'CREATE TABLE daily_cards (
                user_id INTEGER NOT NULL,
                day TEXT NOT NULL,
                reading_id TEXT NOT NULL REFERENCES readings(reading_id) ON DELETE CASCADE,
                PRIMARY KEY (user_id, day)
            )'
        );
        $this->data = new ReadingData($this->pdo);
    }

    private function make(string $id, ?int $userId): Reading
    {
        $r = new Reading();
        $r->setReadingId($id);
        $r->setReadingInfo('{"deck_id":1,"draw":[]}');
        if ($userId !== null) {
            $r->setUserId($userId);
        }
        return $r;
    }

    /** The stored reading $id, failing the test when there is none. */
    private function retrieved(string $id): Reading
    {
        $reading = $this->data->retrieve($id);
        $this->assertNotNull($reading, "No reading '{$id}'");

        return $reading;
    }

    public function testListByUserPagesNewestFirstAndCounts(): void
    {
        $insert = $this->pdo->prepare(
            "INSERT INTO readings (reading_id, reading_info, reading_time, user_id)
             VALUES (:id, '{}', datetime('now', :age), :uid)"
        );
        foreach (['r1' => '-3 days', 'r2' => '-2 days', 'r3' => '-1 days'] as $id => $age) {
            $insert->execute([':id' => $id, ':age' => $age, ':uid' => 7]);
        }
        $insert->execute([':id' => 'other', ':age' => '-1 days', ':uid' => 8]);

        $ids = static fn(array $readings): array => array_map(static fn(Reading $r) => $r->reading_id, $readings);

        $this->assertSame(['r3', 'r2', 'r1'], $ids($this->data->listByUser(7)));
        $this->assertSame(['r3', 'r2'], $ids($this->data->listByUser(7, 2)));
        $this->assertSame(['r1'], $ids($this->data->listByUser(7, 2, 2)));
        $this->assertSame(3, $this->data->countByUser(7));
        $this->assertSame(0, $this->data->countByUser(99));
    }

    public function testCleanGuestOlderThanDeletesOnlyOldGuestReadings(): void
    {
        // The nightly retention job (scripts/retention.php, AUDIT §1.7) relies on
        // this: guest readings past the window go; account readings never do.
        $insert = $this->pdo->prepare(
            "INSERT INTO readings (reading_id, reading_info, reading_time, user_id)
             VALUES (:id, '{}', datetime('now', :age), :uid)"
        );
        $insert->execute([':id' => 'old-guest', ':age' => '-366 days', ':uid' => null]);
        $insert->execute([':id' => 'recent-guest', ':age' => '-364 days', ':uid' => null]);
        $insert->execute([':id' => 'old-owned', ':age' => '-900 days', ':uid' => 7]);

        $this->assertSame(1, $this->data->cleanGuestOlderThan(365));

        $this->assertNull($this->data->retrieve('old-guest'));
        $this->assertNotNull($this->data->retrieve('recent-guest'));
        $this->assertNotNull($this->data->retrieve('old-owned'));
    }

    public function testStoreAndRetrieveNeverExposesTheHashButFlagsProtection(): void
    {
        $r = $this->make('r1', 7);
        $r->setReadingName('My Spread');
        $r->setHideUser(true);
        $this->data->store($r, password_hash('secret123', PASSWORD_DEFAULT));

        $loaded = $this->data->retrieve('r1');
        $this->assertNotNull($loaded);
        $this->assertSame(7, $loaded->user_id);
        $this->assertSame('My Spread', $loaded->reading_name);
        $this->assertTrue($loaded->hide_user);
        $this->assertTrue($loaded->password_protected);

        // The serialized form must not contain the hash.
        $this->assertArrayNotHasKey('password_hash', $loaded->jsonSerialize());
    }

    public function testADailyCardReadingCarriesItsDay(): void
    {
        $this->data->store($this->make('daily', 4));
        $this->data->store($this->make('plain', 4));
        $this->pdo->exec("INSERT INTO daily_cards (user_id, day, reading_id) VALUES (4, '2026-10-30', 'daily')");

        $this->assertSame('2026-10-30', $this->retrieved('daily')->daily_card_day);
        $this->assertNull($this->retrieved('plain')->daily_card_day);
        $this->assertSame(
            ['plain' => null, 'daily' => '2026-10-30'],
            array_column(array_map(static fn(Reading $r): array => $r->jsonSerialize(), $this->data->listByUser(4)), 'daily_card_day', 'reading_id')
        );
    }

    public function testReadingNotesRoundTripAndUpdate(): void
    {
        $r = $this->make('notes1', 3);
        $r->setReadingNotes('# Heading\n\nSome **markdown** notes.');
        $this->data->store($r);

        $loaded = $this->data->retrieve('notes1');
        $this->assertNotNull($loaded);
        $this->assertSame('# Heading\n\nSome **markdown** notes.', $loaded->reading_notes);

        // Owner can edit; clearing sets it back to null.
        $this->data->updateMeta('notes1', 3, ['reading_notes' => 'Updated notes']);
        $this->assertSame('Updated notes', $this->retrieved('notes1')->reading_notes);

        $this->data->updateMeta('notes1', 3, ['reading_notes' => null]);
        $this->assertNull($this->retrieved('notes1')->reading_notes);
    }

    public function testVerifyPassword(): void
    {
        $this->data->store($this->make('r2', 1), password_hash('opensesame', PASSWORD_DEFAULT));
        $this->assertTrue($this->data->verifyPassword('r2', 'opensesame'));
        $this->assertFalse($this->data->verifyPassword('r2', 'wrong'));
        // A reading with no password never verifies.
        $this->data->store($this->make('r3', 1));
        $this->assertFalse($this->data->verifyPassword('r3', 'anything'));
    }

    public function testListByUserReturnsOnlyThatUsersReadings(): void
    {
        $this->data->store($this->make('a', 1));
        $this->data->store($this->make('b', 1));
        $this->data->store($this->make('c', 2));
        $this->data->store($this->make('d', null));

        $this->assertCount(2, $this->data->listByUser(1));
        $this->assertCount(1, $this->data->listByUser(2));
    }

    public function testDeleteForOwnerIsScopedToTheOwner(): void
    {
        $this->data->store($this->make('mine', 1));

        // Wrong owner can't delete it.
        $this->assertFalse($this->data->deleteForOwner('mine', 2));
        $this->assertNotNull($this->data->retrieve('mine'));

        // The owner can.
        $this->assertTrue($this->data->deleteForOwner('mine', 1));
        $this->assertNull($this->data->retrieve('mine'));
    }

    public function testMarkFinalIsScopedToTheOwnerAndIsIdempotent(): void
    {
        $this->data->store($this->make('lockme', 5));
        $this->assertFalseOrNullFinal('lockme');

        // Wrong owner can't lock it.
        $this->assertNull($this->data->markFinal('lockme', 99));
        $this->assertFalse($this->retrieved('lockme')->is_final);

        // The owner can, and a repeat call still reports the (final) state.
        $locked = $this->data->markFinal('lockme', 5);
        $this->assertNotNull($locked);
        $this->assertTrue($locked->is_final);

        $relocked = $this->data->markFinal('lockme', 5);
        $this->assertNotNull($relocked);
        $this->assertTrue($relocked->is_final);
    }

    private function assertFalseOrNullFinal(string $id): void
    {
        $this->assertFalse($this->retrieved($id)->is_final);
    }

    public function testUpdateReadingInfoAppendsDraw(): void
    {
        $this->data->store($this->make('grow', 8));

        $updated = $this->data->updateReadingInfo(
            'grow',
            '{"deck_id":1,"origin":"generated","draw":[{"card_id":4}]}',
            8
        );
        $this->assertNotNull($updated);

        $info = json_decode($updated->reading_info, true);
        $this->assertCount(1, $info['draw']);
        $this->assertSame(4, $info['draw'][0]['card_id']);

        // Wrong owner can't rewrite it.
        $this->assertNull($this->data->updateReadingInfo('grow', '{"deck_id":1,"draw":[]}', 99));
    }

    public function testUpdateMetaIsScopedToTheOwner(): void
    {
        $this->data->store($this->make('owned', 1));

        // Wrong owner → no update, null returned.
        $this->assertNull($this->data->updateMeta('owned', 99, ['reading_name' => 'Hacked']));

        // Correct owner → updates and can set + clear a password.
        $updated = $this->data->updateMeta('owned', 1, [
            'reading_name'  => 'Renamed',
            'hide_user'     => true,
            'password_hash' => password_hash('pw', PASSWORD_DEFAULT),
        ]);
        $this->assertNotNull($updated);
        $this->assertSame('Renamed', $updated->reading_name);
        $this->assertTrue($updated->hide_user);
        $this->assertTrue($updated->password_protected);

        $cleared = $this->data->updateMeta('owned', 1, ['password_hash' => null]);
        $this->assertNotNull($cleared);
        $this->assertFalse($cleared->password_protected);
    }
}
