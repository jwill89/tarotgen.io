<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Service\IndexNow;

#[CoversClass(IndexNow::class)]
final class IndexNowTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['APP_URL']      = 'https://tarotgen.io';
        $_ENV['INDEXNOW_KEY'] = 'testkey123';
    }

    protected function tearDown(): void
    {
        unset($_ENV['APP_URL'], $_ENV['INDEXNOW_KEY']);
    }

    public function testIsConfiguredReflectsKeyPresence(): void
    {
        self::assertTrue((new IndexNow())->isConfigured());

        unset($_ENV['INDEXNOW_KEY']);
        self::assertFalse((new IndexNow())->isConfigured());
    }

    public function testSubmitPostsHostKeyAndUrls(): void
    {
        $captured = null;
        $indexNow = new IndexNow(function (string $endpoint, string $body) use (&$captured): int {
            $captured = ['endpoint' => $endpoint, 'body' => json_decode($body, true)];
            return 200;
        });

        $ok = $indexNow->submit(['https://tarotgen.io/', 'https://tarotgen.io/changelog']);

        self::assertTrue($ok);
        self::assertNotNull($captured);
        self::assertSame('https://api.indexnow.org/indexnow', $captured['endpoint']);
        self::assertSame('tarotgen.io', $captured['body']['host']);
        self::assertSame('testkey123', $captured['body']['key']);
        self::assertSame('https://tarotgen.io/testkey123.txt', $captured['body']['keyLocation']);
        self::assertSame(
            ['https://tarotgen.io/', 'https://tarotgen.io/changelog'],
            $captured['body']['urlList'],
        );
    }

    public function testSubmitTreats202AsSuccessAndOtherStatusesAsFailure(): void
    {
        self::assertTrue((new IndexNow(fn () => 202))->submit(['https://tarotgen.io/']));
        self::assertFalse((new IndexNow(fn () => 403))->submit(['https://tarotgen.io/']));
    }

    public function testSubmitIsNoOpWithoutKeyOrUrls(): void
    {
        $calls    = 0;
        $counting = function () use (&$calls): int {
            $calls++;
            return 200;
        };

        // No URLs: never reaches the network.
        self::assertFalse((new IndexNow($counting))->submit([]));

        // No key configured: also a no-op.
        unset($_ENV['INDEXNOW_KEY']);
        self::assertFalse((new IndexNow($counting))->submit(['https://tarotgen.io/']));

        self::assertSame(0, $calls);
    }
}
