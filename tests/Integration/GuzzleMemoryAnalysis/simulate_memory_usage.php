<?php
/**
 * Analyze Guzzle HTTP client memory usage with large responses.
 *
 * Guzzle uses PSR-7 streams but by default reads the entire body
 * into a PHP stream wrapper. Testing with large in-memory responses
 * to see how the object graph looks.
 */

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;

$pid = getmypid();
echo "PID: $pid\n\n";

$memBaseline = memory_get_usage(true);

// Simulate receiving multiple large HTTP responses
$numResponses = 100;
$bodySize = 500_000; // 500KB each

echo "Creating $numResponses PSR-7 responses (~" . round($bodySize / 1024) . "KB body each)...\n";

$responses = [];
for ($i = 0; $i < $numResponses; $i++) {
    $body = str_repeat("Response body chunk $i. ", $bodySize / 25);
    $responses[] = new Response(200, [
        'Content-Type' => 'application/json',
        'X-Request-Id' => "req-$i-" . bin2hex(random_bytes(8)),
        'Cache-Control' => 'no-cache',
    ], $body);

    if (($i + 1) % 25 === 0) {
        $memNow = memory_get_usage(true);
        echo "  After $i: " . round($memNow / 1024 / 1024, 2) . " MB\n";
    }
}

$memAfter = memory_get_usage(true);
echo "\nFinal: " . round($memAfter / 1024 / 1024, 2) . " MB\n";
echo "Peak: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";
echo "Per response: ~" . round(($memAfter - $memBaseline) / $numResponses / 1024) . " KB\n";

echo "\nREADY_FOR_ANALYSIS\n";
$stdin = fopen('php://stdin', 'r');
stream_set_blocking($stdin, false);
for ($w = 0; $w < 120; $w++) { if (fgets($stdin)) break; sleep(1); }
