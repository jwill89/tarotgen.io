<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Database;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Tarot\Database\Connection;

/**
 * The settings the live database is opened with. The HTTP tests build their own
 * PDO, so only this would notice if, say, foreign keys stopped being enforced
 * and the ON DELETE CASCADEs went quiet. Connection keeps its PDO in a static
 * for the life of the process, so this runs in a process of its own.
 */
#[CoversClass(Connection::class)]
final class ConnectionSettingsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tarot-conn-settings-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/db', 0o777, true);
        new PDO('sqlite:' . $this->dir . '/db/tarotdb.db')->exec('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        putenv('TAROT_DATA_DIR=' . $this->dir);
    }

    protected function tearDown(): void
    {
        putenv('TAROT_DATA_DIR');

        // Windows can't delete a file that's still open: hand Connection a throwaway
        // handle so the one on the test database is released.
        new ReflectionProperty(Connection::class, 'conn')->setValue(null, new PDO('sqlite::memory:'));

        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->dir . '/db/tarotdb.db' . $suffix);
        }
        @rmdir($this->dir . '/db');
        @rmdir($this->dir);
    }

    #[RunInSeparateProcess]
    public function testAnExistingDatabaseOpensWithForeignKeysWalAndABusyTimeout(): void
    {
        $db = Connection::getInstance();

        $this->assertSame(1, $this->pragma($db, 'foreign_keys'));
        $this->assertSame('wal', $this->pragma($db, 'journal_mode'));
        $this->assertSame(5000, $this->pragma($db, 'busy_timeout'));
        $this->assertSame(1, $this->pragma($db, 'synchronous')); // NORMAL
        $this->assertSame(PDO::ERRMODE_EXCEPTION, $db->getAttribute(PDO::ATTR_ERRMODE));
    }

    #[RunInSeparateProcess]
    public function testEveryCallerSharesOneConnection(): void
    {
        $this->assertSame(Connection::getInstance(), Connection::getInstance());
    }

    private function pragma(PDO $db, string $name): mixed
    {
        $statement = $db->query("PRAGMA {$name}");
        $this->assertNotFalse($statement);
        return $statement->fetchColumn();
    }
}
