<?php
// Mandelbrot — pure CPU, deeply nested loop, very few function calls.
// The fairest workload for sampling vs instrumentation in terms of
// "what time profiler is closest to the ground truth?".

$w = (int)($argv[1] ?? 200);
$h = (int)($argv[2] ?? 200);
$max_iter = (int)($argv[3] ?? 200);

$t = microtime(true);
$count = 0;
for ($py = 0; $py < $h; $py++) {
    $y0 = ($py / $h) * 2.0 - 1.0;
    for ($px = 0; $px < $w; $px++) {
        $x0 = ($px / $w) * 2.5 - 1.75;
        $x = 0.0; $y = 0.0; $i = 0;
        while ($x * $x + $y * $y <= 4.0 && $i < $max_iter) {
            $xt = $x * $x - $y * $y + $x0;
            $y  = 2 * $x * $y + $y0;
            $x  = $xt;
            $i++;
        }
        $count += $i;
    }
}
$elapsed = microtime(true) - $t;
fprintf(STDERR, "mandelbrot(%dx%d, max=%d) sum=%d in %.3fs\n",
    $w, $h, $max_iter, $count, $elapsed);
