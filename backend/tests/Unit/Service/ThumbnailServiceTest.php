<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Service\ThumbnailService;

#[CoversClass(ThumbnailService::class)]
final class ThumbnailServiceTest extends TestCase
{
    private string $root;
    private ThumbnailService $service;

    protected function setUp(): void
    {
        if (!\function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP support is required.');
        }

        $this->root = sys_get_temp_dir() . '/tarot_thumbs_' . uniqid();
        mkdir($this->root, 0775, true);
        $this->service = new ThumbnailService($this->root);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function makeCard(int $deckId, int $cardId): void
    {
        $dir = $this->root . '/' . $deckId;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $im = imagecreatetruecolor(600, 1032);
        imagefill($im, 0, 0, imagecolorallocate($im, 80, 40, 160));
        imagepng($im, sprintf('%s/Card_%04d.png', $dir, $cardId));
    }

    public function testEnsureDeckFoldersCreatesDirectories(): void
    {
        $this->service->ensureDeckFolders(7);

        $this->assertDirectoryExists($this->root . '/7');
        $this->assertDirectoryExists($this->root . '/7/thumbs');
    }

    public function testGenerateForDeckCreatesWebpThumbnails(): void
    {
        $this->makeCard(3, 1);
        $this->makeCard(3, 2);

        $result = $this->service->generateForDeck(3);

        $this->assertSame(['deck_id' => 3, 'generated' => 2, 'skipped' => 0], $result);
        $this->assertFileExists($this->root . '/3/thumbs/Card_0001.webp');
        $this->assertFileExists($this->root . '/3/thumbs/Card_0002.webp');
    }

    public function testGenerationIsIdempotent(): void
    {
        $this->makeCard(3, 1);

        $this->service->generateForDeck(3);
        $second = $this->service->generateForDeck(3);

        $this->assertSame(['deck_id' => 3, 'generated' => 0, 'skipped' => 1], $second);
    }

    public function testThumbnailIsDownscaledTo400Wide(): void
    {
        $this->makeCard(5, 1);
        $this->service->generateForDeck(5);

        $info = getimagesize($this->root . '/5/thumbs/Card_0001.webp');
        $this->assertNotFalse($info);
        $this->assertSame(400, $info[0]);
        // 600x1032 -> 400x688 preserves aspect ratio.
        $this->assertSame(688, $info[1]);
    }

    public function testMissingDeckFolderYieldsZeroCounts(): void
    {
        $result = $this->service->generateForDeck(404);
        $this->assertSame(['deck_id' => 404, 'generated' => 0, 'skipped' => 0], $result);
    }

    public function testGenerateAllAggregatesAcrossDecks(): void
    {
        $this->makeCard(1, 1);
        $this->makeCard(1, 2);
        $this->makeCard(2, 1);

        $result = $this->service->generateAll([1, 2]);

        $this->assertSame(['decks' => 2, 'generated' => 3, 'skipped' => 0], $result);
    }
}
