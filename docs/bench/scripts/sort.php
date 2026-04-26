<?php
// Sort benchmark — moderate call volume, medium-shallow stack.
// Some PHP-internal time (sort is in C), some user-call time.

$n = (int)($argv[1] ?? 100000);
$iters = (int)($argv[2] ?? 20);

mt_srand(42);
$base = [];
for ($i = 0; $i < $n; $i++) {
    $base[] = mt_rand();
}

$t = microtime(true);
for ($i = 0; $i < $iters; $i++) {
    $a = $base;
    sort($a);
}
$elapsed = microtime(true) - $t;
fprintf(STDERR, "sort(n=%d, iters=%d) in %.3fs\n", $n, $iters, $elapsed);
