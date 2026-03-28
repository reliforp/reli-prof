<?php
/**
 * Analyze Composer's memory usage by running dependency resolution in-process.
 * Uses a project with 93 packages.
 */

$pid = getmypid();
echo "PID: $pid\n\n";

$projectDir = '/tmp/composer_memory_test';
if (!file_exists("$projectDir/composer.json")) {
    echo "Project not found at $projectDir. Create it first.\n";
    exit(1);
}

// Remove lock to force full resolution
@unlink("$projectDir/composer.lock");

$memBaseline = memory_get_usage(true);

// Run composer update --dry-run as a subprocess, then keep THIS process alive
// with the subprocess output to see peak memory
$cmd = "php -d memory_limit=1G /usr/local/bin/composer update --dry-run --no-interaction --no-scripts --no-plugins --profile -d " . escapeshellarg($projectDir) . " 2>&1";
echo "Running: $cmd\n\n";
$output = shell_exec($cmd);

// Extract memory info from --profile output
preg_match('/Memory usage: (.+)/', $output, $m);
echo "Composer reported: " . ($m[0] ?? 'unknown') . "\n";
echo "Lines: " . substr_count($output, "\n") . "\n";

// Now do it in-process
echo "\n=== In-process resolution ===\n";
@unlink("$projectDir/composer.lock");

register_shutdown_function(function () {
    fwrite(STDERR, "READY_FOR_ANALYSIS PID:" . getmypid() . "\n");
    fwrite(STDERR, "Memory: " . round(memory_get_usage(true)/1024/1024, 2) . " MB\n");
    fwrite(STDERR, "Peak: " . round(memory_get_peak_usage(true)/1024/1024, 2) . " MB\n");
    sleep(120);
});

$_SERVER['argv'] = ['composer', 'update', '--dry-run', '--no-interaction', '--no-scripts', '--no-plugins', '--profile', '-d', $projectDir];
$_SERVER['argc'] = count($_SERVER['argv']);
require '/usr/local/bin/composer';
