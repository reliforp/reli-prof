<?php
// Reproduces glpi-project/glpi shape (Document downloads buffer entire file
// into memory) and the Symfony StreamedJsonResponse shape: one or two huge
// strings that should be the dominant heap consumer. This stresses single
// large-allocation rows in reli's SQLite output (overflow chains in the
// underlying SQLite pages if any column ends up large).

$mb = (int)($argv[1] ?? 16);
$big1 = str_repeat('A', $mb * 1024 * 1024);
$big2 = str_repeat('B', ($mb >> 1) * 1024 * 1024);

// Plus a long-lived array of medium-sized strings to mix shapes.
$lines = [];
for ($i = 0; $i < 2000; $i++) {
    $lines[] = "line_$i: " . str_repeat('z', 240);
}

fwrite(STDERR, "READY pid=" . getmypid()
    . " big1_len=" . strlen($big1)
    . " big2_len=" . strlen($big2)
    . " lines=" . count($lines)
    . " mem=" . memory_get_usage() . "\n");
file_put_contents(getenv("RELI_TARGET_PID_FILE") ?: "/tmp/sqlite-validation/target.pid", getmypid());

while (true) {
    sleep(60);
}
