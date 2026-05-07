<?php

/**
 * Smoke harness for the rmem→sqlite3 ingest paths added in #773 and #781.
 *
 * Builds a synthetic context tree large enough to exercise interior-page
 * formation and the parallel-shard merge, then drives every ingest path
 * the PRs introduce, captures wall-clock + integrity_check + per-table
 * row-set parity vs the direct-write control.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Reli\Inspector\Output\MemoryOutput\BinaryMemoryOutput;
use Reli\Inspector\Output\MemoryOutput\PdoDriver\SqliteDriver;
use Reli\Inspector\Output\MemoryOutput\PdoMemoryOutput;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\BinaryContextTreeSink;
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

fprintf(STDERR, "[direct] writing %s\n", $direct_db);
$pdo = new PdoMemoryOutput(new SqliteDriver($direct_db));
[$dsink, $run_id, $db] = $pdo->createStreamingSink();
$emit($dsink, $node_count);
$pdo->finalizeStreaming($db, $run_id, $dsink, $summary);
unset($pdo, $dsink, $db);

fprintf(STDERR, "[rmem]   writing %s\n", $rmem_path);
$bin_sink = new BinaryContextTreeSink(batch_size: 256);
$emit($bin_sink, $node_count);
(new BinaryMemoryOutput($rmem_path))->finalizeStreaming($bin_sink, $summary);

$variants = [
    'default'                       => [],
    'shared_mmap'                   => ['RELI_SHARED_MMAP_INGEST' => '1'],
    'parallel_index_off'            => ['RELI_PARALLEL_INDEX' => '0'],
    'L_seq'                         => ['RELI_FORMAT_DIRECT_INDEX' => '1'],
    'L_parallel'                    => ['RELI_FORMAT_DIRECT_INDEX' => '1', 'RELI_L_PARALLEL' => '1'],
    'L_partition_4'                 => [
        'RELI_FORMAT_DIRECT_INDEX' => '1',
        'RELI_L_PARALLEL'          => '1',
        'RELI_L_PARTITION_COUNT'   => '4',
    ],
    'L_seq_no_pib'                  => [
        'RELI_FORMAT_DIRECT_INDEX' => '1',
        'RELI_PARALLEL_INDEX'      => '0',
    ],
    'shared_mmap_plus_L_partition'  => [
        'RELI_SHARED_MMAP_INGEST'  => '1',
        'RELI_FORMAT_DIRECT_INDEX' => '1',
        'RELI_L_PARALLEL'          => '1',
        'RELI_L_PARTITION_COUNT'   => '4',
    ],
];

$results = [];
foreach ($variants as $name => $env) {
    $db_path = "{$workdir}/ingest_{$name}.sqlite3";
    foreach ($env as $k => $v) {
        putenv("{$k}={$v}");
        $_ENV[$k] = $v;
    }
    $env_str = $env === [] ? '(none)' : implode(' ', array_map(
        static fn(string $k, string $v) => "{$k}={$v}",
        array_keys($env),
        array_values($env),
    ));
    fprintf(STDERR, "[ingest:%s] env=%s\n", $name, $env_str);
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

$tables_to_diff = [
    'context_nodes'           => ['run_id', 'node_id'],
    'context_edges'           => [
        'run_id', 'parent_node_id', 'child_node_id', 'link_name', 'is_tree', 'strength',
    ],
    'context_node_locations'  => [
        'run_id', 'node_id', 'address', 'size', 'location_type',
        'class_name', 'string_value', 'refcount', 'type_info', 'region',
    ],
    'context_node_attributes' => ['run_id', 'node_id', 'key', 'value'],
    'summary'                 => ['run_id', 'key', 'value'],
    'location_types_summary'  => ['run_id', 'type', 'count', 'memory_usage'],
    'class_objects_summary'   => ['run_id', 'class_name', 'count', 'memory_usage'],
];

$open = static function (string $p): PDO {
    $pdo = new PDO("sqlite:{$p}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
};

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

$direct = $open($direct_db);
echo str_repeat('=', 70) . "\n";
echo "DIRECT integrity_check: " . $run_pragma($direct, 'integrity_check') . "\n";

$summary_lines = [];
foreach ($results as $name => $r) {
    echo str_repeat('=', 70) . "\n";
    echo "VARIANT: {$name}\n";
    if ($r['error'] !== null) {
        echo "  ingest FAILED: {$r['error']}\n";
        $summary_lines[] = sprintf('%-30s  FAILED  %s', $name, $r['error']);
        continue;
    }
    echo sprintf("  ingest ok in %.2fs\n", $r['elapsed']);

    $pdo = $open($r['db']);
    $integ = $run_pragma($pdo, 'integrity_check');
    $fk    = $run_pragma($pdo, 'foreign_key_check');
    echo "  integrity_check    : {$integ}\n";
    echo "  foreign_key_check  : " . ($fk === '' ? '(no rows)' : $fk) . "\n";

    // verify each integer index actually returns rows for an INDEXED BY-pinned lookup
    $idx_probes = [
        'idx_context_edges_run_child' =>
            "SELECT COUNT(*) FROM context_edges INDEXED BY idx_context_edges_run_child "
                . "WHERE run_id=1 AND child_node_id=42",
        'idx_context_node_locations_run_class' =>
            "SELECT COUNT(*) FROM context_node_locations "
                . "INDEXED BY idx_context_node_locations_run_class "
                . "WHERE run_id=1 AND class_name='App\\Class7'",
    ];
    foreach ($idx_probes as $idx => $sql) {
        try {
            $a = (int)$direct->query($sql)->fetchColumn();
            $b = (int)$pdo->query($sql)->fetchColumn();
            $ok = ($a === $b);
            echo "  idx {$idx}: " . ($ok ? "OK ({$b} rows)" : "DIFF (direct={$a}, ingest={$b})") . "\n";
        } catch (\Throwable $e) {
            echo "  idx {$idx}: ERROR " . $e->getMessage() . "\n";
        }
    }

    $diff_count = 0;
    foreach ($tables_to_diff as $t => $cols) {
        $a = $dump_table($direct, $t, $cols);
        $b = $dump_table($pdo, $t, $cols);
        if ($a !== $b) {
            $diff_count++;
            $da = count($a);
            $db_n = count($b);
            echo "  table {$t}: DIFF (direct=$da rows, ingest=$db_n rows)\n";
            $max = min($da, $db_n);
            for ($i = 0; $i < $max; $i++) {
                if ($a[$i] !== $b[$i]) {
                    echo "    first diff @row {$i}\n";
                    echo "      direct: " . json_encode($a[$i]) . "\n";
                    echo "      ingest: " . json_encode($b[$i]) . "\n";
                    break;
                }
            }
        }
    }
    if ($diff_count === 0) {
        echo "  PARITY: OK (all " . count($tables_to_diff) . " tables match direct-write)\n";
        $summary_lines[] = sprintf(
            '%-30s  ok  %.2fs  parity OK  integrity %s',
            $name,
            $r['elapsed'],
            $integ,
        );
    } else {
        echo "  PARITY: {$diff_count} tables differ\n";
        $summary_lines[] = sprintf(
            '%-30s  ok  %.2fs  PARITY %d-DIFF  integrity %s',
            $name,
            $r['elapsed'],
            $diff_count,
            $integ,
        );
    }
}

echo str_repeat('=', 70) . "\n";
echo "SUMMARY\n";
foreach ($summary_lines as $l) {
    echo "  {$l}\n";
}
echo "workdir kept at {$workdir} for ad-hoc inspection (sqlite3 / sqldiff)\n";
