<?php
/**
 * Case 3: Zend MM Chunk Fragmentation
 * Ref: php/php-src#13599
 *
 * Demonstrates memory discrepancy where memory_get_usage() reports
 * much less than actual consumption due to chunk fragmentation
 * with ~1MB string allocations.
 */

$strings = [];
$smallObjects = [];

posix_kill(posix_getpid(), SIGSTOP);

// Allocate 1MB strings that each consume a 2MB chunk
for ($i = 0; $i < 30; $i++) {
    $strings[] = str_repeat('A', 1024 * 1024); // 1MB each

    // Small allocations that pin chunks in memory
    for ($j = 0; $j < 50; $j++) {
        $smallObjects[] = new stdClass();
    }
}

$reported = memory_get_usage(false);
$real = memory_get_usage(true);

echo "Reported usage: " . number_format($reported) . " bytes\n";
echo "Real usage:     " . number_format($real) . " bytes\n";
echo "Waste ratio:    " . round(($real - $reported) / $real * 100, 1) . "%\n";
echo "Strings held:   " . count($strings) . "\n";

// Free half the strings but keep small objects (pinning chunks)
for ($i = 0; $i < 15; $i++) {
    unset($strings[$i]);
}

$reportedAfter = memory_get_usage(false);
$realAfter = memory_get_usage(true);

echo "\nAfter freeing half:\n";
echo "Reported usage: " . number_format($reportedAfter) . " bytes\n";
echo "Real usage:     " . number_format($realAfter) . " bytes\n";
echo "Waste ratio:    " . round(($realAfter - $reportedAfter) / $realAfter * 100, 1) . "%\n";

// Keep process alive for profiling
sleep(300);
