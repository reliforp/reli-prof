<?php
// Wrapper that sleeps before requiring the actual benchmark script.
// This gives external samplers (reli, phpspy) time to attach before
// the timed code begins.
//
// Usage: php run_with_delay.php <script.php> [script args...]

$delay_ms = (int)(getenv('BENCH_ATTACH_DELAY_MS') ?: 250);
usleep($delay_ms * 1000);

$script = $argv[1] ?? null;
if ($script === null) {
    fwrite(STDERR, "usage: run_with_delay.php <script.php> [args...]\n");
    exit(1);
}

// Shift our wrapper args off so the inner script sees its own argv.
$inner_args = array_slice($argv, 2);
$argv = array_merge([$script], $inner_args);
$argc = count($argv);

require $script;
