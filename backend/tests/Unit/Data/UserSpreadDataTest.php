<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Data;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Data\NormalizesPositions;
use Tarot\Data\UserSpreadData;
use Tarot\Structure\UserSpread;

/**
 * CRUD + ownership scoping for a user's personal spreads, against an in-memory
 * SQLite database.
 */
#[CoversClass(UserSpreadData::class)]
#[CoversClass(NormalizesPositions::class)]
#[CoversClass(UserSpread::class)]
final class UserSpreadDataTest extends TestCase
{
    private PDO $pdo;
    private UserSpreadData $data;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            "CREATE TABLE user_spreads (
                user_spread_id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                name TEXT NOT NULL DEFAULT '',
                description TEXT NOT NULL DEFAULT '',
                card_count INTEGER NOT NULL DEFAULT 1,
                positions TEXT NOT NULL DEFAULT '[]',
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );
        $this->data = new UserSpreadData($this->pdo);
    }

    private function samplePositions(int $n = 2): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = ['order' => $i, 'title' => "P$i", 'x' => 50, 'y' => 50, 'rotation' => 0];
        }
        return $out;
    }

    public function testStoreAndRetrieveRoundTrips(): void
    {
        $created = $this->data->store(1, [
            'name'        => 'Three Card',
            'description' => 'past/present/future',
            'positions'   => $this->samplePositions(3),
        ]);

        $this->assertInstanceOf(UserSpread::class, $created);
        $this->assertSame('Three Card', $created->name);
        $this->assertSame(1, $created->user_id);
        // card_count defaults to the position count when not supplied.
        $this->assertSame(3, $created->card_count);
        $this->assertCount(3, $created->positions);
    }

    public function testRetrieveIsScopedToTheOwningUser(): void
    {
        $mine = $this->data->store(1, ['name' => 'Mine', 'positions' => $this->samplePositions()]);
        $this->data->store(2, ['name' => 'Theirs', 'positions' => $this->samplePositions()]);

        $listForUser1 = $this->data->retrieve(1);
        $this->assertCount(1, $listForUser1);
        $this->assertSame('Mine', $listForUser1[0]->name);

        // Scoped fetch by id returns nothing for the wrong user.
        $this->assertCount(0, $this->data->retrieve(2, $mine->user_spread_id));
        $this->assertCount(1, $this->data->retrieve(1, $mine->user_spread_id));
    }

    public function testFindByIdIgnoresOwnership(): void
    {
        // Used when generating a reading from any personal spread.
        $created = $this->data->store(5, ['name' => 'Anyone', 'positions' => $this->samplePositions()]);
        $found = $this->data->findById($created->user_spread_id);
        $this->assertInstanceOf(UserSpread::class, $found);
        $this->assertSame(5, $found->user_id);
        $this->assertNull($this->data->findById(99999));
    }

    public function testUpdateRecountsCardsWhenPositionsChangeButNotCardCountGiven(): void
    {
        $created = $this->data->store(1, ['name' => 'Start', 'positions' => $this->samplePositions(2)]);

        $updated = $this->data->update(1, $created->user_spread_id, [
            'name'      => 'Grown',
            'positions' => $this->samplePositions(5),
        ]);

        $this->assertSame('Grown', $updated->name);
        $this->assertSame(5, $updated->card_count); // recomputed from positions
        $this->assertCount(5, $updated->positions);
    }

    public function testUpdateCannotTouchAnotherUsersSpread(): void
    {
        $created = $this->data->store(1, ['name' => 'Mine', 'positions' => $this->samplePositions()]);

        // User 2 attempts to rename user 1's spread → no matching row, no change.
        $result = $this->data->update(2, $created->user_spread_id, ['name' => 'Hijacked']);
        $this->assertNull($result);
        $this->assertSame('Mine', $this->data->findById($created->user_spread_id)->name);
    }

    public function testUpdateWithNoFieldsReturnsNull(): void
    {
        $created = $this->data->store(1, ['name' => 'Mine', 'positions' => $this->samplePositions()]);
        $this->assertNull($this->data->update(1, $created->user_spread_id, []));
    }

    public function testDeleteIsOwnershipScoped(): void
    {
        $created = $this->data->store(1, ['name' => 'Mine', 'positions' => $this->samplePositions()]);
        $id = $created->user_spread_id;

        // Wrong owner can't delete it.
        $this->data->delete(2, $id);
        $this->assertNotNull($this->data->findById($id));

        // Correct owner can.
        $this->data->delete(1, $id);
        $this->assertNull($this->data->findById($id));
    }
}
