<?php
// Suspended generators retain captured locals and the symbol table
// of the suspended frame. Long-lived generators are a common shape
// in async libs (React, AmpHP, Revolt). zend_generator has a private
// layout reli's memory code visits via "internal_run_time_cache" /
// "execute_data" pointers — distinct from regular call frames.

function streamRows(int $i, array $context): \Generator {
    $local_buffer = [];
    while (true) {
        $local_buffer[] = "row_{$i}_" . count($local_buffer);
        yield ['ctx' => $context, 'i' => $i, 'buf_size' => count($local_buffer)];
        if (count($local_buffer) > 8) {
            $local_buffer = array_slice($local_buffer, -4);
        }
    }
}

$count = (int)($argv[1] ?? 1500);
$gens  = [];

for ($i = 0; $i < $count; $i++) {
    $context = ['tags' => ['t' . ($i % 5), 't' . ($i % 13)], 'origin' => "src_$i"];
    $g = streamRows($i, $context);
    // Drive the generator a few steps so it has a non-empty local buffer
    // suspended on the yield.
    for ($k = 0; $k < ($i % 5) + 2; $k++) {
        $g->current();
        $g->next();
    }
    $gens[] = $g;
}

fwrite(STDERR, "READY pid=" . getmypid()
    . " generators=" . count($gens)
    . " mem=" . memory_get_usage() . "\n");
file_put_contents(getenv('RELI_TARGET_PID_FILE') ?: "/tmp/sqlite-validation/target.pid", getmypid());
while (true) { sleep(60); }
