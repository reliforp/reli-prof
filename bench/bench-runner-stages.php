<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Amp\Parallel\Context;

$n = (int) ($argv[1] ?? getenv('RELI_BENCH_N') ?: 5);
$mode = extension_loaded('parallel') ? 'thread' : 'process';
$stage = getenv('RELI_BENCH_STAGE') ?: 'services';

$readField = static function (string $path, string $field): int {
    $contents = @file_get_contents($path);
    if ($contents === false) return 0;
    if (preg_match('/^' . preg_quote($field, '/') . ':\s+(\d+)/m', $contents, $m)) {
        return (int) $m[1];
    }
    return 0;
};

$contexts = [];
$workers = [];
for ($i = 0; $i < $n; $i++) {
    $ctx = Context\startContext(__DIR__ . '/bench-worker-stages.php');
    $contexts[] = $ctx;
    $workers[] = $ctx->receive();
}

$pss = $readField('/proc/self/smaps_rollup', 'Pss');
$rss = $readField('/proc/self/status', 'VmRSS');
fwrite(STDOUT, sprintf("mode=%s stage=%s N=%d total_rss_kb=%d total_pss_kb=%d\n", $mode, $stage, $n, $rss, $pss));

foreach ($contexts as $ctx) {
    $ctx->send('shutdown');
    $ctx->join();
}
