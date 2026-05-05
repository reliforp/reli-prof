<?php

/**
 * Smoke harness for PR #773 SQLite ingest paths.
 *
 * Builds a synthetic context tree large enough to exercise interior-page
 * formation and the parallel-shard merge, then drives every ingest path
 * the PR introduces:
 *
 *   - direct write (legacy, control)
 *   - ingestFromRmem default (parallel shard + format-direct merge)
 *   - ingestFromRmem with RELI_SHARED_MMAP_INGEST=1
 *   - ingestFromRmem with RELI_PARALLEL_INDEX=1
 *   - ingestFromRmem with RELI_FORMAT_DIRECT_INDEX=1
 *
 * For each output:
 *   1. PRAGMA integrity_check / foreign_key_check / quick_check
 *   2. Per-table row-set parity vs the direct-write control
 *      (excluding the auto-id surrogate columns)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Reli\Inspector\Output\MemoryOutput\BinaryMemoryOutput;
use Reli\Inspector\Output\MemoryOutput\PdoDriver\SqliteDriver;
use Reli\Inspector\Output\MemoryOutput\PdoMemoryOutput;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\BinaryContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\PdoContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;

$node_count = (int)($argv[1] ?? 5000);
$workdir = sys_get_temp_dir() . '/reli_smoke_' . getmypid();
@mkdir($workdir);
fprintf(STDERR, "[setup] node_count=%d workdir=%s\n", $node_count, $workdir);

$summary = [[
    'memory_get_usage'   => 16384,
    'php_version'        => '8.4',
    'zend_mm_heap_usage' => 8192,
]];

/**
 * Emit a synthetic tree with a mix of objects and strings, plus a handful
 * of cross-edges (canonical-id alias paths) and shared-address pairs.
 */
$emit = static function (object $sink, int $n): void {
    for ($i = 1; $i <= $n; $i++) {
        $parent = $i === 1 ? null : intdiv($i, 2);
        $is_string = ($i % 5) === 0;
        if ($is_string) {
            $loc = new ZendStringMemoryLocation(
                address: 0x10000 + $i * 0x40,
                size: 32 + ($i % 7) * 8,
                refcount: 1,
                type_info: 6,
                value: "value_{$i}",
            );
            $type = 'StringContext';
        } else {
            $loc = new ZendObjectMemoryLocation(
                address: 0x10000 + $i * 0x40,
                size: 64 + ($i % 11) * 16,
                refcount: 1 + ($i % 3),
                type_info: 7,
                class_name: 'App\\Class' . ($i % 50),
            );
            $type = 'ObjectContext';
        }
        $attrs = ($i % 3 === 0) ? ['note' => "n{$i}"] : [];
        $sink->emitNode(
            node_id: $i,
            parent_node_id: $parent,
            link_name: 'child_' . ($i % 4),
            type: $type,
            locations: [$loc],
            attributes: $attrs,
        );
    }
    // shared-ref edges
    for ($i = 0; $i < min(50, $n); $i++) {
        $a = max(1, intdiv($n, 4) + $i);
        $b = max(1, $n - $i);
        if ($a !== $b) {
            $sink->emitReference(reference_node_id: $b, parent_node_id: $a, link_name: 'shared_ref');
        }
    }
};

$direct_db = "{$workdir}/direct.sqlite3";
$rmem_path = "{$workdir}/cap.rmem";

// --- 1. direct-write control --------------------------------------------
fprintf(STDERR, "[direct] writing %s\n", $direct_db);
$pdo = new PdoMemoryOutput(new SqliteDriver($direct_db));
[$dsink, $run_id, $db] = $pdo->createStreamingSink();
$emit($dsink, $node_count);
$pdo->finalizeStreaming($db, $run_id, $dsink, $summary);
unset($pdo, $dsink, $db);

// --- 2. capture rmem once, reuse for every ingest variant ---------------
fprintf(STDERR, "[rmem]   writing %s\n", $rmem_path);
$bin_sink = new BinaryContextTreeSink(batch_size: 256);
$emit($bin_sink, $node_count);
(new BinaryMemoryOutput($rmem_path))->finalizeStreaming($bin_sink, $summary);

// --- 3. drive every ingest variant --------------------------------------
$variants = [
    'default'            => [],
    'shared_mmap'        => ['RELI_SHARED_MMAP_INGEST' => '1'],
    'parallel_index'     => ['RELI_PARALLEL_INDEX' => '1'],
    'format_direct_idx'  => ['RELI_FORMAT_DIRECT_INDEX' => '1'],
];

$results = [];
foreach ($variants as $name => $env) {
    $db_path = "{$workdir}/ingest_{$name}.sqlite3";
    foreach ($env as $k => $v) {
        putenv("{$k}={$v}");
        $_ENV[$k] = $v;
    }
    fprintf(STDERR, "[ingest:%s] %s\n", $name, $db_path);
    try {
        $t0 = microtime(true);
        $out = new PdoMemoryOutput(new SqliteDriver($db_path));
        $out->ingestFromRmem($rmem_path, $summary);
        $elapsed = microtime(true) - $t0;
        unset($out);
        $results[$name] = ['db' => $db_path, 'elapsed' => $elapsed, 'error' => null];
    } catch (\Throwable $e) {
        $results[$name] = [
            'db' => $db_path,
            'elapsed' => null,
            'error' => $e::class . ': ' . $e->getMessage(),
        ];
    }
    foreach (array_keys($env) as $k) {
        putenv($k);
        unset($_ENV[$k]);
    }
}

// --- 4. integrity + parity checks ---------------------------------------
$tables_to_diff = [
    'context_nodes'            => ['run_id', 'node_id'],
    'context_edges'            => [
        'run_id', 'parent_node_id', 'child_node_id', 'link_name', 'is_tree', 'strength',
    ],
    'context_node_locations'   => [
        'run_id', 'node_id', 'address', 'size', 'location_type',
        'class_name', 'string_value', 'refcount', 'type_info', 'region',
    ],
    'context_node_attributes'  => ['run_id', 'node_id', 'key', 'value'],
    'summary'                  => ['run_id', 'key', 'value'],
    'location_types_summary'   => ['run_id', 'type', 'count', 'memory_usage'],
    'class_objects_summary'    => ['run_id', 'class_name', 'count', 'memory_usage'],
];

$open = static function (string $p): PDO {
    $pdo = new PDO("sqlite:{$p}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
};

$direct = $open($direct_db);

$run_pragma = static function (PDO $pdo, string $name): string {
    $rows = $pdo->query("PRAGMA {$name}")->fetchAll(PDO::FETCH_NUM);
    $vals = array_map(static fn(array $r) => (string)$r[0], $rows);
    return implode(';', $vals);
};

$dump_table = static function (PDO $pdo, string $table, array $cols): array {
    $list = implode(',', array_map(static fn(string $c): string => '"' . $c . '"', $cols));
    return $pdo->query("SELECT {$list} FROM {$table} ORDER BY {$list}")
        ->fetchAll(PDO::FETCH_ASSOC) ?: [];
};

$report = ['direct' => ['integrity' => $run_pragma($direct, 'integrity_check')]];
echo str_repeat('=', 70) . "\n";
echo "DIRECT integrity_check: " . $report['direct']['integrity'] . "\n";

foreach ($results as $name => $r) {
    echo str_repeat('=', 70) . "\n";
    echo "VARIANT: {$name}\n";
    if ($r['error'] !== null) {
        echo "  ingest FAILED: {$r['error']}\n";
        continue;
    }
    echo sprintf("  ingest ok in %.2fs (%s)\n", $r['elapsed'], $r['db']);

    $pdo = $open($r['db']);
    $integ = $run_pragma($pdo, 'integrity_check');
    $fk    = $run_pragma($pdo, 'foreign_key_check');
    $quick = $run_pragma($pdo, 'quick_check');
    echo "  integrity_check    : {$integ}\n";
    echo "  quick_check        : {$quick}\n";
    echo "  foreign_key_check  : " . ($fk === '' ? '(no rows)' : $fk) . "\n";

    $diff_count = 0;
    foreach ($tables_to_diff as $t => $cols) {
        $a = $dump_table($direct, $t, $cols);
        $b = $dump_table($pdo, $t, $cols);
        if ($a !== $b) {
            $diff_count++;
            $da = count($a);
            $db_n = count($b);
            echo "  table {$t}: DIFF (direct=$da rows, ingest=$db_n rows)\n";
            // show first differing row
            $max = min($da, $db_n);
            for ($i = 0; $i < $max; $i++) {
                if ($a[$i] !== $b[$i]) {
                    echo "    first diff @row {$i}\n";
                    echo "      direct: " . json_encode($a[$i]) . "\n";
                    echo "      ingest: " . json_encode($b[$i]) . "\n";
                    break;
                }
            }
        } else {
            echo "  table {$t}: OK (" . count($a) . " rows)\n";
        }
    }
    if ($diff_count === 0) {
        echo "  PARITY: OK (all " . count($tables_to_diff) . " tables match direct-write)\n";
    } else {
        echo "  PARITY: {$diff_count} tables differ\n";
    }
}

echo str_repeat('=', 70) . "\n";
echo "workdir kept at {$workdir} for ad-hoc inspection (sqlite3 / sqldiff)\n";
