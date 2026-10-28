<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tarot\Config\Paths;
use Tarot\Database\Connection;

/**
 * Only the failure path is tested here: Connection caches its PDO in a static
 * that can't be reset, so a successful open would leak into every later test.
 */
#[CoversClass(Connection::class)]
#[CoversClass(Paths::class)]
final class ConnectionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tarot-conn-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/db', 0777, true);
        putenv('TAROT_DATA_DIR=' . $this->dir);
    }

    protected function tearDown(): void
    {
        putenv('TAROT_DATA_DIR');
        @unlink($this->dir . '/db/tarotdb.db');
        @rmdir($this->dir . '/db');
        @rmdir($this->dir);
    }

    public function testAMissingDatabaseIsAnErrorNotANewEmptyFile(): void
    {
        try {
            Connection::getInstance();
            $this->fail('Expected a RuntimeException for a missing database.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('TAROT_DATA_DIR', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->dir . '/db/tarotdb.db');
    }
}
