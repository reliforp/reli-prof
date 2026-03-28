<?php
/**
 * Run `composer show` and keep the process alive for reli-prof analysis.
 * Composer loads full package metadata into memory.
 */

$pid = getmypid();
echo "PID: $pid\n\n";

// Run composer show to populate memory with package data
chdir(dirname(__DIR__, 3)); // reli-prof root

echo "Running composer show...\n";
$output = shell_exec('composer show 2>&1');
echo "Packages loaded: " . substr_count($output, "\n") . " lines\n";
echo "Memory: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB\n";
echo "Peak: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";

// Keep the output in memory to simulate real usage
$packages = explode("\n", $output);

echo "\nREADY_FOR_ANALYSIS\n";

$stdin = fopen('php://stdin', 'r');
stream_set_blocking($stdin, false);
for ($w = 0; $w < 120; $w++) { if (fgets($stdin)) break; sleep(1); }
