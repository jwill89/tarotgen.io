<?php

/**
 * One-off icon generator. Produces PWA icons in public/ from the 512x512
 * assets/favicon.png source using GD. Run: php scripts/gen_icons.php
 */

$src = imagecreatefrompng(__DIR__ . '/../assets/favicon.png');
if ($src === false) {
    fwrite(STDERR, "Could not load assets/favicon.png\n");
    exit(1);
}
imagealphablending($src, true);
imagesavealpha($src, true);

$outDir = __DIR__ . '/../public';

/** Resize the source onto a transparent square of $size and save. */
function resizeTransparent($src, int $size, string $path): void
{
    $dst = imagecreatetruecolor($size, $size);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefill($dst, 0, 0, $transparent);
    $sw = imagesx($src);
    $sh = imagesy($src);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $size, $size, $sw, $sh);
    imagepng($dst, $path);
    imagedestroy($dst);
    echo "wrote $path\n";
}

/**
 * Composite the source (scaled into the maskable safe zone) onto a vertical
 * mystical gradient background. Used for the maskable + apple-touch icons.
 */
function maskable($src, int $size, float $inner, string $path): void
{
    $dst = imagecreatetruecolor($size, $size);
    imagealphablending($dst, true);
    imagesavealpha($dst, true);

    // Vertical gradient from #1f1640 (top) to #0e0b1c (bottom).
    [$r1, $g1, $b1] = [0x1f, 0x16, 0x40];
    [$r2, $g2, $b2] = [0x0e, 0x0b, 0x1c];
    for ($y = 0; $y < $size; $y++) {
        $t = $y / ($size - 1);
        $c = imagecolorallocate(
            $dst,
            (int)round($r1 + ($r2 - $r1) * $t),
            (int)round($g1 + ($g2 - $g1) * $t),
            (int)round($b1 + ($b2 - $b1) * $t)
        );
        imageline($dst, 0, $y, $size, $y, $c);
    }

    // Scale the logo into the central safe zone and centre it.
    $target = (int)round($size * $inner);
    $offset = (int)round(($size - $target) / 2);
    $sw = imagesx($src);
    $sh = imagesy($src);
    imagecopyresampled($dst, $src, $offset, $offset, 0, 0, $target, $target, $sw, $sh);

    imagepng($dst, $path);
    imagedestroy($dst);
    echo "wrote $path\n";
}

resizeTransparent($src, 512, $outDir . '/icon-512.png');
resizeTransparent($src, 192, $outDir . '/icon-192.png');
// Maskable: logo within ~62% safe zone on a full-bleed gradient.
maskable($src, 512, 0.62, $outDir . '/icon-maskable-512.png');
// Apple touch icon: iOS ignores transparency, so use the filled background too.
maskable($src, 180, 0.78, $outDir . '/apple-touch-icon.png');

imagedestroy($src);
echo "done\n";
