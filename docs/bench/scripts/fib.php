<?php
// Recursive fibonacci — heavy call frequency, shallow stack.
// Worst-case for per-call instrumentation profilers.

function fib(int $n): int {
    return $n < 2 ? $n : fib($n - 1) + fib($n - 2);
}

$n = (int)($argv[1] ?? 32);
$t = microtime(true);
$result = fib($n);
$elapsed = microtime(true) - $t;
fprintf(STDERR, "fib(%d) = %d in %.3fs\n", $n, $result, $elapsed);
