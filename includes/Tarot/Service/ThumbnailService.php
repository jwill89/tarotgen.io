<?php

namespace Tarot\Service;

/**
 * Generates 400px-wide WebP thumbnails for a deck's card images, replacing the
 * old ffmpeg shell script with pure PHP/GD so it can run from the admin panel.
 *
 * Card images live at  assets/decks/<deck_id>/Card_XXXX.png
 * Thumbnails are written to assets/decks/<deck_id>/thumbs/Card_XXXX.webp
 *
 * Generation is idempotent: existing thumbnails are skipped, so re-running is
 * cheap and only new images do work.
 */
class ThumbnailService
{
    private const int THUMB_WIDTH = 400;
    private const int WEBP_QUALITY = 80;

    private string $decksRoot;

    /**
     * @param string|null $decksRoot Override the deck-images root (for tests).
     *                               Defaults to the repo's assets/decks.
     */
    public function __construct(?string $decksRoot = null)
    {
        // includes/Tarot/Service -> repo root -> assets/decks
        $this->decksRoot = $decksRoot ?? (dirname(__DIR__, 3) . '/assets/decks');
    }

    /** Create the deck's image folder (and thumbs/) if they don't exist yet. */
    public function ensureDeckFolders(int $deckId): void
    {
        $dir = $this->deckDir($deckId);
        $thumbs = $dir . '/thumbs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_dir($thumbs)) {
            @mkdir($thumbs, 0775, true);
        }
    }

    /**
     * Generate any missing thumbnails for one deck.
     *
     * @return array{deck_id:int,generated:int,skipped:int}
     */
    public function generateForDeck(int $deckId): array
    {
        $dir = $this->deckDir($deckId);
        $generated = 0;
        $skipped = 0;

        if (is_dir($dir)) {
            $thumbDir = $dir . '/thumbs';
            if (!is_dir($thumbDir)) {
                @mkdir($thumbDir, 0775, true);
            }

            foreach (glob($dir . '/Card_*.png') ?: [] as $png) {
                $webp = $thumbDir . '/' . basename($png, '.png') . '.webp';
                if (is_file($webp)) {
                    $skipped++;
                    continue;
                }
                if ($this->makeThumb($png, $webp)) {
                    $generated++;
                }
            }
        }

        return ['deck_id' => $deckId, 'generated' => $generated, 'skipped' => $skipped];
    }

    /**
     * Generate missing thumbnails across many decks.
     *
     * @param  int[] $deckIds
     * @return array{decks:int,generated:int,skipped:int}
     */
    public function generateAll(array $deckIds): array
    {
        $generated = 0;
        $skipped = 0;

        foreach ($deckIds as $deckId) {
            $result = $this->generateForDeck((int)$deckId);
            $generated += $result['generated'];
            $skipped += $result['skipped'];
        }

        return ['decks' => count($deckIds), 'generated' => $generated, 'skipped' => $skipped];
    }

    private function deckDir(int $deckId): string
    {
        return $this->decksRoot . '/' . $deckId;
    }

    /** Scale a PNG to THUMB_WIDTH (never upscaling) and write it as WebP. */
    private function makeThumb(string $png, string $webp): bool
    {
        $src = @imagecreatefrompng($png);
        if ($src === false) {
            return false;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        if ($w <= 0 || $h <= 0) {
            return false;
        }

        $tw = min(self::THUMB_WIDTH, $w);
        $th = max(1, (int)round($h * ($tw / $w)));

        $dst = imagecreatetruecolor($tw, $th);
        // Preserve transparency (some card backs have alpha).
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);

        // GdImage instances are freed automatically by the garbage collector
        // since PHP 8.0; imagedestroy() was deprecated in 8.5.
        return imagewebp($dst, $webp, self::WEBP_QUALITY);
    }
}
