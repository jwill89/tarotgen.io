<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Data;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Data\AbstractData;

/**
 * Concrete subclass that exposes the protected helpers so we can exercise the
 * shared Data-layer plumbing against an in-memory SQLite database.
 */
final class ExposedData extends AbstractData
{
    public function callApplyUpdate(
        string $table,
        array $data,
        array $allowed,
        array $where,
        array $intCols = [],
        array $boolCols = []
    ): bool {
        return $this->applyUpdate($table, $data, $allowed, $where, $intCols, $boolCols);
    }
}

#[CoversClass(AbstractData::class)]
final class AbstractDataTest extends TestCase
{
    private PDO $pdo;
    private ExposedData $data;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE widgets (
                id INTEGER PRIMARY KEY,
                name TEXT,
                qty INTEGER,
                active INTEGER,
                secret TEXT
            )'
        );
        $this->pdo->exec("INSERT INTO widgets (id, name, qty, active, secret)
                          VALUES (1, 'orig', 0, 0, 'keep')");

        $this->data = new ExposedData($this->pdo);
    }

    private function row(int $id = 1): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM widgets WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function testUpdatesOnlyAllowedColumns(): void
    {
        $changed = $this->data->callApplyUpdate(
            'widgets',
            ['name' => 'new', 'secret' => 'hacked'],
            ['name'],            // only name is allowed
            ['id' => 1]
        );

        $this->assertTrue($changed);

        $row = $this->row();
        $this->assertSame('new', $row['name']);
        $this->assertSame('keep', $row['secret']); // untouched: not in allow-list
    }

    public function testReturnsFalseWhenNoAllowedFieldsPresent(): void
    {
        $changed = $this->data->callApplyUpdate(
            'widgets',
            ['secret' => 'hacked'], // not allowed
            ['name'],
            ['id' => 1]
        );

        $this->assertFalse($changed);
        $this->assertSame('keep', $this->row()['secret']);
    }

    public function testIntColumnsAreCastToInteger(): void
    {
        $this->data->callApplyUpdate(
            'widgets',
            ['qty' => '15'],
            ['qty'],
            ['id' => 1],
            ['qty']
        );

        $this->assertSame(15, $this->row()['qty']);
    }

    public function testBoolColumnsAreCastToZeroOrOne(): void
    {
        $this->data->callApplyUpdate('widgets', ['active' => 'yes'], ['active'], ['id' => 1], [], ['active']);
        $this->assertSame(1, $this->row()['active']);

        $this->data->callApplyUpdate('widgets', ['active' => ''], ['active'], ['id' => 1], [], ['active']);
        $this->assertSame(0, $this->row()['active']);
    }

    public function testWhereClauseScopesTheUpdate(): void
    {
        $this->pdo->exec("INSERT INTO widgets (id, name, qty, active, secret)
                          VALUES (2, 'other', 0, 0, 'keep')");

        $this->data->callApplyUpdate('widgets', ['name' => 'only-one'], ['name'], ['id' => 2]);

        $this->assertSame('orig', $this->row(1)['name']);     // untouched
        $this->assertSame('only-one', $this->row(2)['name']); // updated
    }
}
