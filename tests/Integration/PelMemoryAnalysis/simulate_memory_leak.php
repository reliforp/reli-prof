<?php
/**
 * Reproduce FileEye/pel#29 - memory leak when processing JPEG files.
 *
 * The bug: pel accumulates memory when processing files in a loop via:
 * 1. file_get_contents() usage in PelDataWindow (whole file in memory)
 * 2. Exception objects accumulated in Pel::$exceptions
 *
 * We generate test JPEG files with EXIF data and process them sequentially.
 */

require_once __DIR__ . '/vendor/autoload.php';

use lsolesen\pel\Pel;
use lsolesen\pel\PelJpeg;

$pid = getmypid();
echo "PID: $pid\n";
echo "Memory limit: " . ini_get('memory_limit') . "\n\n";

// Generate test JPEG files
$testDir = __DIR__ . '/test_images';
if (!is_dir($testDir)) {
    mkdir($testDir);
}

$numFiles = 30;

echo "Generating $numFiles large test JPEG files (~2-3MB each)...\n";
for ($i = 0; $i < $numFiles; $i++) {
    $file = "$testDir/test_$i.jpg";
    if (!file_exists($file) || filesize($file) < 1_000_000) {
        // Create a large JPEG (2048x1536 with noise for high entropy = bigger file)
        $img = imagecreatetruecolor(2048, 1536);
        for ($x = 0; $x < 2048; $x += 2) {
            for ($y = 0; $y < 1536; $y += 2) {
                $c = imagecolorallocate($img, rand(0, 255), rand(0, 255), rand(0, 255));
                imagesetpixel($img, $x, $y, $c);
                imagesetpixel($img, $x+1, $y, $c);
                imagesetpixel($img, $x, $y+1, $c);
                imagesetpixel($img, $x+1, $y+1, $c);
            }
        }
        imagejpeg($img, $file, 95);
        imagedestroy($img);
    }
}
echo "File size: ~" . round(filesize("$testDir/test_0.jpg") / 1024) . "KB\n";
echo "Done.\n\n";

$memBaseline = memory_get_usage(true);
echo "Baseline: " . round($memBaseline / 1024 / 1024, 2) . " MB\n\n";

// Process files sequentially — this should show memory leak
echo "Processing $numFiles JPEG files with pel...\n";
for ($i = 0; $i < $numFiles; $i++) {
    $file = "$testDir/test_$i.jpg";

    try {
        $jpeg = new PelJpeg($file);
        $exif = $jpeg->getExif();

        // Try to read some data
        if ($exif) {
            $tiff = $exif->getTiff();
        }
    } catch (\Exception $e) {
        // Ignore parse errors
    }

    // Explicitly unset to try freeing memory
    unset($jpeg, $exif, $tiff);

    if (($i + 1) % 10 === 0) {
        $memNow = memory_get_usage(true);
        $delta = $memNow - $memBaseline;
        echo sprintf("  After %3d files: %.2f MB (delta: +%.2f MB)\n",
            $i + 1, $memNow / 1024 / 1024, $delta / 1024 / 1024);
    }
}

$memAfter = memory_get_usage(true);
echo "\nFinal: " . round($memAfter / 1024 / 1024, 2) . " MB\n";
echo "Peak: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";

// Try Pel::clearExceptions() if available
if (method_exists(Pel::class, 'clearExceptions')) {
    Pel::clearExceptions();
    echo "After clearExceptions: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB\n";
}

gc_collect_cycles();
echo "After GC: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB\n";

echo "\nREADY_FOR_ANALYSIS\n";
sleep(60);
