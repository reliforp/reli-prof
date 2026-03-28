<?php
/**
 * Analyze Monolog memory usage with BufferHandler.
 *
 * BufferHandler accumulates log records until flush. In long-running
 * processes, this can consume significant memory if not configured
 * with a buffer limit.
 */

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\BufferHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\NullHandler;

$pid = getmypid();
echo "PID: $pid\n\n";

$memBaseline = memory_get_usage(true);

// Scenario 1: BufferHandler without limit (the dangerous pattern)
echo "=== Scenario 1: BufferHandler without limit ===\n";
$logger = new Logger('test');
$logger->pushHandler(new BufferHandler(new NullHandler()));

$numLogs = 100_000;
echo "Writing $numLogs log entries...\n";

for ($i = 0; $i < $numLogs; $i++) {
    $logger->info("Log entry $i: " . str_repeat('data', 20), [
        'user_id' => rand(1, 1000),
        'action' => 'test_action_' . ($i % 10),
        'timestamp' => microtime(true),
        'extra' => ['ip' => '192.168.1.' . ($i % 255), 'ua' => 'Mozilla/5.0'],
    ]);

    if (($i + 1) % 20000 === 0) {
        $memNow = memory_get_usage(true);
        echo "  After " . ($i+1) . ": " . round($memNow / 1024 / 1024, 2) . " MB\n";
    }
}

$memAfter = memory_get_usage(true);
echo "Final: " . round($memAfter / 1024 / 1024, 2) . " MB (delta: +" . round(($memAfter - $memBaseline) / 1024 / 1024, 2) . " MB)\n";
echo "Per log entry: ~" . round(($memAfter - $memBaseline) / $numLogs) . " bytes\n";

echo "\nREADY_FOR_ANALYSIS\n";
$stdin = fopen('php://stdin', 'r');
stream_set_blocking($stdin, false);
for ($w = 0; $w < 120; $w++) { if (fgets($stdin)) break; sleep(1); }
