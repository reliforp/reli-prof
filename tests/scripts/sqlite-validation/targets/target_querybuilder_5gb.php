<?php
// Reproduces nextcloud/server "Memory of doctrine/dbal QueryBuilder.php
// reached 5GB" + flysystem DirectoryListing iterator shapes:
// long-running iteration that retains every row instead of yielding.
// We won't actually allocate 5GB (sandbox), but we exercise the same
// shape: ~50K nested arrays, deeply nested.

$rows = (int)($argv[1] ?? 30000);
$results = [];

for ($i = 0; $i < $rows; $i++) {
    $results[] = [
        'id'         => $i,
        'path'       => '/files/' . ($i % 200) . '/' . $i . '.dat',
        'size'       => $i * 17 % 1_000_000,
        'permissions'=> ['read' => true, 'write' => $i % 2 === 0, 'exec' => false],
        'metadata'   => [
            'mtime' => 1700000000 + $i,
            'mime'  => $i % 3 === 0 ? 'application/octet-stream' : 'text/plain',
            'tags'  => ['t' . ($i % 5), 't' . ($i % 13)],
        ],
    ];
}

fwrite(STDERR, "READY pid=" . getmypid() . " rows=" . count($results)
    . " mem=" . memory_get_usage() . "\n");
file_put_contents(getenv("RELI_TARGET_PID_FILE") ?: "/tmp/sqlite-validation/target.pid", getmypid());

while (true) {
    sleep(60);
}
