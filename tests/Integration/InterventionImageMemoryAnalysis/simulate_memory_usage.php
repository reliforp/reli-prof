<?php
/**
 * Analyze Intervention/image memory usage.
 *
 * Image processing creates GD resources and wrapper objects.
 * Test: process many images in a loop, check if memory leaks.
 */

require_once __DIR__ . '/vendor/autoload.php';

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

$pid = getmypid();
echo "PID: $pid\n\n";

$manager = new ImageManager(new Driver());
$memBaseline = memory_get_usage(true);
echo "Baseline: " . round($memBaseline / 1024 / 1024, 2) . " MB\n\n";

// Create and process images in a loop
$numImages = 50;
echo "Processing $numImages images (create → resize → encode → discard)...\n";

$allEncoded = []; // Keep encoded results to prevent GC

for ($i = 0; $i < $numImages; $i++) {
    // Create a 1024x768 image with random content
    $img = $manager->create(1024, 768);

    // Draw some content
    $img->fill('ff' . str_pad(dechex(rand(0, 255)), 2, '0', STR_PAD_LEFT) . str_pad(dechex(rand(0, 255)), 2, '0', STR_PAD_LEFT));
    // Resize
    $img->resize(512, 384);

    // Encode to JPEG
    $encoded = $img->toJpeg(75);
    $allEncoded[] = (string) $encoded;

    // Explicit cleanup
    unset($img, $encoded);

    if (($i + 1) % 10 === 0) {
        $memNow = memory_get_usage(true);
        echo sprintf("  Image %2d: %.2f MB (delta: +%.2f MB, encoded total: %d KB)\n",
            $i + 1, $memNow / 1024 / 1024, ($memNow - $memBaseline) / 1024 / 1024,
            array_sum(array_map('strlen', $allEncoded)) / 1024);
    }
}

$memFinal = memory_get_usage(true);
echo "\nFinal: " . round($memFinal / 1024 / 1024, 2) . " MB\n";
echo "Peak: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";

echo "\nREADY_FOR_ANALYSIS\n";
$stdin = fopen('php://stdin', 'r');
stream_set_blocking($stdin, false);
for ($w = 0; $w < 120; $w++) { if (fgets($stdin)) break; sleep(1); }
