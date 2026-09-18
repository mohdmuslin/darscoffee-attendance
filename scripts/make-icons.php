<?php

/*
 * One-off generator for the PWA icons.
 *
 * Drawn rather than shipped as binaries so the artwork is reviewable in a diff and
 * can be regenerated at any size if the branding changes. Run from the project root:
 *
 *   php scripts/make-icons.php
 *
 * SAFETY GATE: writes into public/icons, which on a live host is served to browsers. Guarded
 * alongside the other helpers so a stray run on the server cannot overwrite the deployed icons.
 */

require_once __DIR__.'/dev-guard.php';

dev_require_local();

$out = __DIR__.'/../public/icons';

if (! is_dir($out)) {
    mkdir($out, 0777, true);
}

const BG = [0x78, 0x35, 0x0F];   // amber-900, matches theme-color

function canvas(int $size): GdImage
{
    $image = imagecreatetruecolor($size, $size);
    $bg = imagecolorallocate($image, ...BG);

    // Full bleed, never transparent: a maskable icon is cropped to a circle on
    // Android, and a transparent background would leave a faded edge.
    imagefilledrectangle($image, 0, 0, $size, $size, $bg);

    return $image;
}

/**
 * @param  float  $radius  Clock radius as a fraction of the icon size.
 */
function clock(GdImage $image, int $size, float $radius): GdImage
{
    $centre = $size / 2;
    $r = $size * $radius;
    $white = imagecolorallocate($image, 0xFF, 0xFF, 0xFF);
    $bg = imagecolorallocate($image, ...BG);

    // Ring: a white disc with the background punched out of the middle.
    imagefilledellipse($image, (int) $centre, (int) $centre, (int) ($r * 2), (int) ($r * 2), $white);

    $thickness = max(2, (int) round($size * 0.045));
    imagefilledellipse($image, (int) $centre, (int) $centre, (int) (($r - $thickness) * 2), (int) (($r - $thickness) * 2), $bg);

    // Hands at roughly ten past ten, which reads as a clock at a glance.
    imagesetthickness($image, $thickness);
    imageline($image, (int) $centre, (int) $centre, (int) $centre, (int) ($centre - $r * 0.55), $white);
    imageline($image, (int) $centre, (int) $centre, (int) ($centre + $r * 0.42), (int) ($centre + $r * 0.22), $white);
    imagesetthickness($image, 1);

    return $image;
}

function save(GdImage $image, string $path): void
{
    imagepng($image, $path);
    imagedestroy($image);

    echo basename($path).' written'.PHP_EOL;
}

// Standard icons: artwork may touch the edges.
save(clock(canvas(192), 192, 0.34), $out.'/icon-192.png');
save(clock(canvas(512), 512, 0.34), $out.'/icon-512.png');

// Maskable: Android may crop to a circle of 80% of the width, so the clock is kept
// inside that safe zone or the ring would be sliced off.
$maskable = canvas(512);
clock($maskable, 512, 0.26);
save($maskable, $out.'/icon-maskable-512.png');

$apple = canvas(180);
clock($apple, 180, 0.32);
save($apple, $out.'/apple-touch-icon.png');

$favicon = canvas(64);
clock($favicon, 64, 0.3);
save($favicon, $out.'/../favicon.png');
