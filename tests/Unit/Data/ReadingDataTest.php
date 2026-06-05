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
                password_hash TEXT DEFAULT NULL
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

    public function testStoreAndRetrieveNeverExposesTheHashButFlagsProtection(): void
    {
        $r = $this->make('r1', 7);
        $r->setReadingName('My Spread');
        $r->setHideUser(true);
        $this->data->store($r, password_hash('secret123', PASSWORD_DEFAULT));

        $loaded = $this->data->retrieve('r1');
        $this->assertNotNull($loaded);
        $this->assertSame(7, $loaded->getUserId());
        $this->assertSame('My Spread', $loaded->getReadingName());
        $this->assertTrue($loaded->isHideUser());
        $this->assertTrue($loaded->isPasswordProtected());

        // The serialized form must not contain the hash.
        $this->assertArrayNotHasKey('password_hash', $loaded->jsonSerialize());
    }

    public function testReadingNotesRoundTripAndUpdate(): void
    {
        $r = $this->make('notes1', 3);
        $r->setReadingNotes('# Heading\n\nSome **markdown** notes.');
        $this->data->store($r, null);

        $loaded = $this->data->retrieve('notes1');
        $this->assertSame('# Heading\n\nSome **markdown** notes.', $loaded->getReadingNotes());

        // Owner can edit; clearing sets it back to null.
        $this->data->updateMeta('notes1', 3, ['reading_notes' => 'Updated notes']);
        $this->assertSame('Updated notes', $this->data->retrieve('notes1')->getReadingNotes());

        $this->data->updateMeta('notes1', 3, ['reading_notes' => null]);
        $this->assertNull($this->data->retrieve('notes1')->getReadingNotes());
    }

    public function testVerifyPassword(): void
    {
        $this->data->store($this->make('r2', 1), password_hash('opensesame', PASSWORD_DEFAULT));
        $this->assertTrue($this->data->verifyPassword('r2', 'opensesame'));
        $this->assertFalse($this->data->verifyPassword('r2', 'wrong'));
        // A reading with no password never verifies.
        $this->data->store($this->make('r3', 1), null);
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

    public function testUpdateMetaIsScopedToTheOwner(): void
    {
        $this->data->store($this->make('owned', 1), null);

        // Wrong owner → no update, null returned.
        $this->assertNull($this->data->updateMeta('owned', 99, ['reading_name' => 'Hacked']));

        // Correct owner → updates and can set + clear a password.
        $updated = $this->data->updateMeta('owned', 1, [
            'reading_name'  => 'Renamed',
            'hide_user'     => true,
            'password_hash' => password_hash('pw', PASSWORD_DEFAULT),
        ]);
        $this->assertNotNull($updated);
        $this->assertSame('Renamed', $updated->getReadingName());
        $this->assertTrue($updated->isHideUser());
        $this->assertTrue($updated->isPasswordProtected());

        $cleared = $this->data->updateMeta('owned', 1, ['password_hash' => null]);
        $this->assertFalse($cleared->isPasswordProtected());
    }
}
