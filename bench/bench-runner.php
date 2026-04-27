<?php

declare(strict_types=1);

/**
 * Spawns N workers via amphp/parallel and reports memory.
 *
 *   - process mode: invoke directly under NTS PHP CLI;
 *     DefaultContextFactory falls back to ProcessContextFactory.
 *   - thread  mode: invoke via bench/bench-launcher.php so this script runs
 *     inside the ffi-zts ZTS embed; ext-parallel is loaded there and
 *     DefaultContextFactory auto-selects ThreadContextFactory.
 *
 * Worker count via $argv[1] or RELI_BENCH_N env var (the embed does not
 * forward argv, hence the env-var path).
 */

require __DIR__ . '/../vendor/autoload.php';

use Amp\Parallel\Context;

$n = (int) ($argv[1] ?? getenv('RELI_BENCH_N') ?: 3);
$mode = extension_loaded('parallel') ? 'thread' : 'process';

$readField = static function (string $path, string $field): int {
    $contents = @file_get_contents($path);
    if ($contents === false) {
        return 0;
    }
    if (preg_match('/^' . preg_quote($field, '/') . ':\s+(\d+)/m', $contents, $m)) {
        return (int) $m[1];
    }
    return 0;
};

$selfStatus = '/proc/self/status';
$selfRollup = '/proc/self/smaps_rollup';

$beforeRss = $readField($selfStatus, 'VmRSS');
$beforePss = $readField($selfRollup, 'Pss');

$contexts = [];
$workers = [];
for ($i = 0; $i < $n; $i++) {
    $ctx = Context\startContext(__DIR__ . '/bench-worker.php');
    $contexts[] = $ctx;
    $workers[] = $ctx->receive();
}

$afterRss = $readField($selfStatus, 'VmRSS');
$afterPss = $readField($selfRollup, 'Pss');

$factoryClass = get_class(Context\contextFactory());
fwrite(STDOUT, "mode={$mode} N={$n} factory={$factoryClass}\n");
fwrite(STDOUT, "parent_pid=" . getmypid() . " parent_rss_kb={$afterRss} parent_pss_kb={$afterPss}\n");

$totalWorkerRss = 0;
$totalWorkerPss = 0;
foreach ($workers as $i => $w) {
    if ($mode === 'process') {
        $wRss = $readField("/proc/{$w['pid']}/status", 'VmRSS');
        $wPss = $readField("/proc/{$w['pid']}/smaps_rollup", 'Pss');
        $totalWorkerRss += $wRss;
        $totalWorkerPss += $wPss;
        fwrite(STDOUT, sprintf(
            "  worker[%d] pid=%d rss_kb=%d pss_kb=%d php_heap_kb=%d\n",
            $i, $w['pid'], $wRss, $wPss, $w['php_heap_kb'],
        ));
    } else {
        fwrite(STDOUT, sprintf(
            "  worker[%d] thread_pid=%d php_heap_kb=%d (rss/pss covered by parent)\n",
            $i, $w['pid'], $w['php_heap_kb'],
        ));
    }
}

if ($mode === 'process') {
    fwrite(STDOUT, sprintf(
        "TOTAL rss_kb=%d pss_kb=%d\n",
        $afterRss + $totalWorkerRss,
        $afterPss + $totalWorkerPss,
    ));
} else {
    fwrite(STDOUT, sprintf(
        "TOTAL rss_kb=%d pss_kb=%d (single process, all threads)\n",
        $afterRss,
        $afterPss,
    ));
}

foreach ($contexts as $ctx) {
    $ctx->send('shutdown');
    $ctx->join();
}
