<?php
// Stress the format-direct merge's "leaf cell larger than inline
// payload threshold" path. SqliteRaw\Format requires falling back to
// the SQL merge path when a single cell would exceed the inline
// threshold (~4 KiB minus headers — strings longer than ~4000 bytes
// stored verbatim in context_node_locations.string_value should
// trigger OverflowNotSupportedException).
//
// We allocate strings in a wide range — some short, some right at
// the threshold, some way past it. reli should produce
// integrity-clean SQLite either via the format-direct path (small
// strings only, no overflow) or via the SQL fallback (any cell too
// big for inline payload).

$count = (int)($argv[1] ?? 200);

$buckets = [];
for ($i = 0; $i < $count; $i++) {
    // Scale string size from 16 B up to 64 KiB, sometimes well past
    // any inline-payload threshold.
    $len = match ($i % 7) {
        0 => 16,
        1 => 256,
        2 => 1500,        // borderline — depends on PHP zval/zend_string overhead
        3 => 4000,        // very near 4 KiB inline limit
        4 => 16384,       // 16 KiB — definitely overflow
        5 => 65536,       // 64 KiB
        6 => 524288,      // 512 KiB — really stress the overflow chain
    };
    $key = "row_$i";
    $buckets[$key] = [
        'id' => $i,
        'short' => "tag-$i",
        'long' => str_repeat(chr(ord('a') + ($i % 26)), $len),
    ];
}

fwrite(STDERR, "READY pid=" . getmypid() . " buckets=" . count($buckets)
    . " mem=" . memory_get_usage() . "\n");
file_put_contents(getenv('RELI_TARGET_PID_FILE') ?: '/tmp/sqlite-validation/target.pid', getmypid());
while (true) { sleep(60); }
