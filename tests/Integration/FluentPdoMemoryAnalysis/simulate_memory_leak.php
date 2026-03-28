<?php
/**
 * Reproduce FluentPDO memory leak (envms/fluentpdo#337).
 *
 * The bug: FluentPDO retains internal references to the PDO connection
 * across operations, causing refcount to grow (103 after 100 inserts)
 * and preventing GC from freeing query objects.
 *
 * Uses SQLite (no external DB required).
 */

require_once __DIR__ . '/vendor/autoload.php';

use Envms\FluentPDO\Query;

$pid = getmypid();
echo "PID: $pid\n";
echo "Memory limit: " . ini_get('memory_limit') . "\n\n";

// Create in-memory SQLite database
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE test (id INTEGER PRIMARY KEY AUTOINCREMENT, data TEXT)');

$fluent = new Query($pdo);

// Generate large content for inserts
$bigContent = str_repeat('X', 50_000); // 50KB per row

$memBaseline = memory_get_usage(true);
echo "Baseline: " . round($memBaseline / 1024 / 1024, 2) . " MB\n\n";

// Phase 1: Raw PDO inserts (control group)
echo "Phase 1: 200 raw PDO inserts...\n";
$stmt = $pdo->prepare('INSERT INTO test (data) VALUES (?)');
for ($i = 0; $i < 200; $i++) {
    $stmt->execute([$bigContent]);
}
$memAfterPdo = memory_get_usage(true);
echo "  Memory after PDO inserts: " . round($memAfterPdo / 1024 / 1024, 2) . " MB\n";
echo "  Delta from baseline: +" . round(($memAfterPdo - $memBaseline) / 1024 / 1024, 2) . " MB\n\n";

// Reset
$pdo->exec('DELETE FROM test');

// Phase 2: FluentPDO inserts (test group)
echo "Phase 2: 200 FluentPDO inserts...\n";
for ($i = 0; $i < 200; $i++) {
    $fluent->insertInto('test')->values(['data' => $bigContent])->execute();

    if (($i + 1) % 50 === 0) {
        $memNow = memory_get_usage(true);
        echo "  After $i inserts: " . round($memNow / 1024 / 1024, 2) . " MB\n";
    }
}
$memAfterFluent = memory_get_usage(true);
echo "  Memory after FluentPDO inserts: " . round($memAfterFluent / 1024 / 1024, 2) . " MB\n";
echo "  Delta from baseline: +" . round(($memAfterFluent - $memBaseline) / 1024 / 1024, 2) . " MB\n";
echo "  FluentPDO overhead vs PDO: +" . round(($memAfterFluent - $memAfterPdo) / 1024 / 1024, 2) . " MB\n\n";

echo "Phase 3: gc_collect_cycles()...\n";
$collected = gc_collect_cycles();
$memAfterGC = memory_get_usage(true);
echo "  Cycles collected: $collected\n";
echo "  Memory after GC: " . round($memAfterGC / 1024 / 1024, 2) . " MB\n";

echo "\nREADY_FOR_ANALYSIS\n";
echo "Attach: sudo php /home/user/reli-prof/reli inspector:memory -p $pid --output-format=sqlite3 --output=/tmp/reli_fluentpdo.sqlite3\n";
sleep(60);
