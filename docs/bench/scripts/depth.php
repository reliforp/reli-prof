<?php
// Synthesised deep-stack benchmark — variable call depth × calls.
// At constant total work, increasing depth lowers call count, so the
// instrumentation/sampling cost trade-off shifts.

function deep(int $depth, int $work): int {
    if ($depth <= 0) {
        // Fixed amount of work at the leaf.
        $s = 0;
        for ($i = 0; $i < $work; $i++) $s += $i * $i;
        return $s;
    }
    return deep($depth - 1, $work);
}

$depth = (int)($argv[1] ?? 30);
$iters = (int)($argv[2] ?? 200000);
$work  = (int)($argv[3] ?? 100);

$t = microtime(true);
$acc = 0;
for ($i = 0; $i < $iters; $i++) {
    $acc += deep($depth, $work);
}
$elapsed = microtime(true) - $t;
fprintf(STDERR, "depth(%d) iters=%d work=%d acc=%d in %.3fs\n",
    $depth, $iters, $work, $acc, $elapsed);
