<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Service\CardImageService;

#[CoversClass(CardImageService::class)]
final class CardImageServiceTest extends TestCase
{
    private string $root;
    private CardImageService $service;

    protected function setUp(): void
    {
        if (!\function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP support is required.');
        }

        $this->root = sys_get_temp_dir() . '/tarot_images_' . uniqid();
        mkdir($this->root, 0775, true);
        $this->service = new CardImageService($this->root);
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

    private function makeCard(int $deckId, int $cardId): string
    {
        $dir = $this->root . '/' . $deckId;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $im = imagecreatetruecolor(600, 1032);
        imagefill($im, 0, 0, imagecolorallocate($im, 80, 40, 160));
        $path = sprintf('%s/Card_%04d.png', $dir, $cardId);
        imagepng($im, $path);

        return $path;
    }

    public function testEnsureDeckFoldersCreatesDirectories(): void
    {
        $this->service->ensureDeckFolders(7);

        $this->assertDirectoryExists($this->root . '/7');
        $this->assertDirectoryExists($this->root . '/7/thumbs');
    }

    public function testGenerateForDeckWritesDisplayCopiesAndThumbnails(): void
    {
        $this->makeCard(3, 1);
        $this->makeCard(3, 2);

        $result = $this->service->generateForDeck(3);

        $this->assertSame(['deck_id' => 3, 'generated' => 2, 'skipped' => 0, 'failed' => 0], $result);
        foreach (['Card_0001', 'Card_0002'] as $name) {
            $this->assertFileExists("{$this->root}/3/{$name}.webp");
            $this->assertFileExists("{$this->root}/3/thumbs/{$name}.webp");
        }
    }

    public function testDisplayCopyKeepsFullSizeAndThumbnailIs400Wide(): void
    {
        $this->makeCard(5, 1);
        $this->service->generateForDeck(5);

        $display = getimagesize($this->root . '/5/Card_0001.webp');
        $thumb = getimagesize($this->root . '/5/thumbs/Card_0001.webp');
        $this->assertNotFalse($display);
        $this->assertNotFalse($thumb);
        $this->assertSame([600, 1032], [$display[0], $display[1]]);
        // 600x1032 -> 400x688 preserves aspect ratio.
        $this->assertSame([400, 688], [$thumb[0], $thumb[1]]);
    }

    public function testCurrentImagesAreSkipped(): void
    {
        $this->makeCard(3, 1);

        $this->service->generateForDeck(3);
        $second = $this->service->generateForDeck(3);

        $this->assertSame(['deck_id' => 3, 'generated' => 0, 'skipped' => 1, 'failed' => 0], $second);
    }

    public function testAReplacedScanIsRegenerated(): void
    {
        $png = $this->makeCard(3, 1);
        $this->service->generateForDeck(3);

        // A re-scan replaces the PNG, leaving the derived files older than it.
        touch($this->root . '/3/Card_0001.webp', time() - 60);
        touch($this->root . '/3/thumbs/Card_0001.webp', time() - 60);
        clearstatcache();

        $result = $this->service->generateForDeck(3);
        $this->assertSame(1, $result['generated']);
        $this->assertGreaterThanOrEqual(filemtime($png), filemtime($this->root . '/3/Card_0001.webp'));
    }

    public function testAMissingDerivedFileIsRegenerated(): void
    {
        $this->makeCard(3, 1);
        $this->service->generateForDeck(3);
        unlink($this->root . '/3/Card_0001.webp');

        $result = $this->service->generateForDeck(3);

        $this->assertSame(1, $result['generated']);
        $this->assertFileExists($this->root . '/3/Card_0001.webp');
    }

    public function testAnUndecodablePngIsCountedAsFailedAndLeavesNothingBehind(): void
    {
        mkdir($this->root . '/9', 0775, true);
        file_put_contents($this->root . '/9/Card_0001.png', 'not a png');

        $result = @$this->service->generateForDeck(9);

        $this->assertSame(['deck_id' => 9, 'generated' => 0, 'skipped' => 0, 'failed' => 1], $result);
        $this->assertSame([], glob($this->root . '/9/*.webp*') ?: []);
        $this->assertSame([], glob($this->root . '/9/thumbs/*') ?: []);
    }

    public function testMissingDeckFolderYieldsZeroCounts(): void
    {
        $result = $this->service->generateForDeck(404);
        $this->assertSame(['deck_id' => 404, 'generated' => 0, 'skipped' => 0, 'failed' => 0], $result);
    }

    public function testGenerateAllAggregatesAcrossDecks(): void
    {
        $this->makeCard(1, 1);
        $this->makeCard(1, 2);
        $this->makeCard(2, 1);

        $result = $this->service->generateAll([1, 2]);

        $this->assertSame(['decks' => 2, 'generated' => 3, 'skipped' => 0, 'failed' => 0], $result);
    }
}
