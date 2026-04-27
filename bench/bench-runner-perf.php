<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Amp\Parallel\Context;

$n = (int) ($argv[1] ?? getenv('RELI_BENCH_N') ?: 1);
$mode = extension_loaded('parallel') ? 'thread' : 'process';

$targets = explode(',', getenv('RELI_BENCH_TARGETS') ?: '');
$targets = array_map('intval', array_filter($targets));
if (count($targets) < $n) {
    fwrite(STDERR, "need at least {$n} target pids in RELI_BENCH_TARGETS, got " . count($targets) . "\n");
    exit(1);
}

$contexts = [];
for ($i = 0; $i < $n; $i++) {
    $contexts[] = Context\startContext(__DIR__ . '/bench-worker-perf.php');
}
foreach ($contexts as $i => $ctx) {
    $ctx->send($targets[$i]);
}
$results = [];
foreach ($contexts as $ctx) {
    $results[] = $ctx->receive();
}

$tag = getenv('RELI_BENCH_TAG') ?: $mode;

foreach ($results as $r) {
    fwrite(STDOUT, sprintf(
        "%-22s zts=%s jit=%s samples=%d captured=%d wall=%.1fs cpu=%.1fs us/sample(wall)=%.2f us/sample(cpu)=%.2f\n",
        $tag,
        var_export($r['php_zts'], true),
        var_export($r['jit'], true),
        $r['samples'],
        $r['captured'],
        $r['wall_us'] / 1_000_000,
        $r['cpu_us'] / 1_000_000,
        $r['us_per_sample_wall'],
        $r['us_per_sample_cpu'],
    ));
}

foreach ($contexts as $ctx) {
    $ctx->send('shutdown');
    $ctx->join();
}
