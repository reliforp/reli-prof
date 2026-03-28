<?php
/**
 * Analyze PHP_CodeSniffer memory usage on reli-prof's own codebase.
 *
 * phpcs tokenizes and analyzes every PHP file, building token arrays
 * and error/warning lists. Large codebases can consume significant memory.
 */

$pid = getmypid();
$reliRoot = dirname(__DIR__, 3);
echo "PID: $pid\n\n";

$memBaseline = memory_get_usage(true);

// Run phpcs on reli-prof's own src/ directory
echo "Running phpcs on reli-prof src/...\n";
$_SERVER['argv'] = [
    'phpcs',
    '--standard=PSR12',
    '--no-colors',
    '--report=summary',
    "$reliRoot/src/",
];
$_SERVER['argc'] = count($_SERVER['argv']);

// Keep process alive after phpcs finishes
register_shutdown_function(function () use ($memBaseline) {
    $memAfter = memory_get_usage(true);
    fwrite(STDERR, "\nMemory: " . round($memAfter / 1024 / 1024, 2) . " MB\n");
    fwrite(STDERR, "Delta: +" . round(($memAfter - $memBaseline) / 1024 / 1024, 2) . " MB\n");
    fwrite(STDERR, "Peak: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n");
    fwrite(STDERR, "READY_FOR_ANALYSIS PID:" . getmypid() . "\n");
    sleep(120);
});

require "$reliRoot/vendor/bin/phpcs";
