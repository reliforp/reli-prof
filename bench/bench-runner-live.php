<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Amp\Parallel\Context;

$n = (int) ($argv[1] ?? getenv('RELI_BENCH_N') ?: 3);
$mode = extension_loaded('parallel') ? 'thread' : 'process';

$readField = static function (string $path, string $field): int {
    $contents = @file_get_contents($path);
    if ($contents === false) return 0;
    if (preg_match('/^' . preg_quote($field, '/') . ':\s+(\d+)/m', $contents, $m)) {
        return (int) $m[1];
    }
    return 0;
};

$targets = explode(',', getenv('RELI_BENCH_TARGETS') ?: '');
$targets = array_map('intval', array_filter($targets));
if (count($targets) < $n) {
    fwrite(STDERR, "need at least {$n} target pids in RELI_BENCH_TARGETS, got " . count($targets) . "\n");
    exit(1);
}

$contexts = [];
for ($i = 0; $i < $n; $i++) {
    $contexts[] = Context\startContext(__DIR__ . '/bench-worker-live.php');
}
foreach ($contexts as $i => $ctx) {
    $ctx->send($targets[$i]);
}
$results = [];
foreach ($contexts as $ctx) {
    $results[] = $ctx->receive();
}

$pss = $readField('/proc/self/smaps_rollup', 'Pss');
$rss = $readField('/proc/self/status', 'VmRSS');
fwrite(STDOUT, sprintf("mode=%s N=%d total_rss_kb=%d total_pss_kb=%d\n", $mode, $n, $rss, $pss));

if ($mode === 'process') {
    $worker_rss = 0;
    $worker_pss = 0;
    foreach ($results as $r) {
        $w_rss = $readField("/proc/{$r['pid']}/status", 'VmRSS');
        $w_pss = $readField("/proc/{$r['pid']}/smaps_rollup", 'Pss');
        $worker_rss += $w_rss;
        $worker_pss += $w_pss;
        fwrite(STDOUT, sprintf(
            "  worker pid=%d target=%d captured=%d/%d php_heap_kb=%d rss_kb=%d pss_kb=%d\n",
            $r['pid'], $r['target_pid'], $r['captured'], $r['samples'],
            $r['php_heap_kb'], $w_rss, $w_pss,
        ));
    }
    fwrite(STDOUT, sprintf("TOTAL_RSS=%d TOTAL_PSS=%d\n", $rss + $worker_rss, $pss + $worker_pss));
} else {
    foreach ($results as $r) {
        fwrite(STDOUT, sprintf(
            "  worker thread_pid=%d target=%d captured=%d/%d php_heap_kb=%d\n",
            $r['pid'], $r['target_pid'], $r['captured'], $r['samples'], $r['php_heap_kb'],
        ));
    }
    fwrite(STDOUT, sprintf("TOTAL_RSS=%d TOTAL_PSS=%d (single process, all threads)\n", $rss, $pss));
}

foreach ($contexts as $ctx) {
    $ctx->send('shutdown');
    $ctx->join();
}
