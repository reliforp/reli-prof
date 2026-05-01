<?php

/**
 * Synthetic benchmark for the unify-memory-output factory change.
 *
 * Drives both pipelines side-by-side with the same emitted node/edge
 * stream, then reports JSON and Report end-to-end times for the legacy
 * temp-SQLite intermediate vs the new temp-rmem intermediate.
 *
 * Usage:
 *   php tools/bench-unify-memory-output.php [node_count]
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Reli\Inspector\Output\MemoryOutput\BinaryMemoryOutput;
use Reli\Inspector\Output\MemoryOutput\MemoryAnalysisResult;
use Reli\Inspector\Output\MemoryOutput\PdoDriver\SqliteDriver;
use Reli\Inspector\Output\MemoryOutput\PdoMemoryOutput;
use Reli\Inspector\Output\MemoryOutput\JsonMemoryOutput;
use Reli\Inspector\Output\MemoryOutput\ReportMemoryOutput;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\BinaryContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;

$node_count = (int)($argv[1] ?? 20000);
echo "Synthetic node count: {$node_count}\n";

function emit_tree(iterable|object $sink, int $n): void
{
    for ($i = 1; $i <= $n; $i++) {
        $parent = $i === 1 ? null : intdiv($i, 2);
        $is_string = ($i % 5) === 0;
        $loc = $is_string
            ? new ZendStringMemoryLocation(
                address: 0x10000 + $i * 0x40,
                size: 32 + ($i % 7) * 8,
                refcount: 1,
                type_info: 6,
                value: "value_{$i}",
            )
            : new ZendObjectMemoryLocation(
                address: 0x10000 + $i * 0x40,
                size: 64 + ($i % 11) * 16,
                refcount: 1 + ($i % 3),
                type_info: 7,
                class_name: 'App\\Class' . ($i % 50),
            );
        $attrs = ($i % 3 === 0) ? ['note' => "n{$i}"] : [];
        $sink->emitNode(
            node_id: $i,
            parent_node_id: $parent,
            link_name: 'child_' . ($i % 4),
            type: $is_string ? 'StringContext' : 'ObjectContext',
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
}

/**
 * @return array{collect: float, output: float, total: float}
 */
function bench_pdo_intermediate_json(int $n, string $tmp_out): array
{
    $tmp_db = tempnam(sys_get_temp_dir(), 'reli_bench_pdo_') . '.sqlite3';
    @unlink(substr($tmp_db, 0, -9));

    $t0 = microtime(true);
    $driver = new SqliteDriver($tmp_db);
    $pdo_output = new PdoMemoryOutput($driver);
    [$sink, $run_id, $db] = $pdo_output->createStreamingSink();
    emit_tree($sink, $n);
    $summary = [['memory_get_usage' => 1024]];
    $pdo_output->finalizeStreaming($db, $run_id, $sink, $summary);
    $t_collect = microtime(true) - $t0;

    $t1 = microtime(true);
    $result = new MemoryAnalysisResult($summary, null, null, null, $db, $run_id);
    $json = new JsonMemoryOutput(false, $tmp_out);
    $json->output($result);
    $t_output = microtime(true) - $t1;

    @unlink($tmp_db);
    @unlink($tmp_out);
    return ['collect' => $t_collect, 'output' => $t_output, 'total' => $t_collect + $t_output];
}

/**
 * @return array{collect: float, output: float, total: float}
 */
function bench_rmem_intermediate_json(int $n, string $tmp_out): array
{
    $tmp_rmem = tempnam(sys_get_temp_dir(), 'reli_bench_rmem_') . '.rmem';
    @unlink(substr($tmp_rmem, 0, -5));

    $t0 = microtime(true);
    $bin_out = new BinaryMemoryOutput($tmp_rmem);
    $sink = $bin_out->createStreamingSink();
    emit_tree($sink, $n);
    $summary = [['memory_get_usage' => 1024]];
    $bin_out->finalizeStreaming($sink, $summary);
    $t_collect = microtime(true) - $t0;

    $t1 = microtime(true);
    $result = new MemoryAnalysisResult(
        summary: $summary,
        context: null,
        pre_populated_rmem_path: $tmp_rmem,
    );
    $json = new JsonMemoryOutput(false, $tmp_out);
    $json->output($result);
    $t_output = microtime(true) - $t1;

    @unlink($tmp_rmem);
    @unlink($tmp_out);
    return ['collect' => $t_collect, 'output' => $t_output, 'total' => $t_collect + $t_output];
}

/**
 * @return array{collect: float, output: float, total: float}
 */
function bench_pdo_intermediate_report(int $n, string $tmp_out): array
{
    $tmp_db = tempnam(sys_get_temp_dir(), 'reli_bench_pdo_') . '.sqlite3';
    @unlink(substr($tmp_db, 0, -9));

    $t0 = microtime(true);
    $driver = new SqliteDriver($tmp_db);
    $pdo_output = new PdoMemoryOutput($driver);
    [$sink, $run_id, $db] = $pdo_output->createStreamingSink();
    emit_tree($sink, $n);
    $summary = [['memory_get_usage' => 1024, 'php_version' => '8.4', 'zend_mm_heap_usage' => 1024]];
    $pdo_output->finalizeStreaming($db, $run_id, $sink, $summary);
    $t_collect = microtime(true) - $t0;

    $t1 = microtime(true);
    $result = new MemoryAnalysisResult($summary, null, null, null, $db, $run_id);
    $rep = ReportMemoryOutput::text(null, $tmp_out);
    $rep->output($result);
    $t_output = microtime(true) - $t1;

    @unlink($tmp_db);
    @unlink($tmp_out);
    return ['collect' => $t_collect, 'output' => $t_output, 'total' => $t_collect + $t_output];
}

/**
 * @return array{collect: float, output: float, total: float}
 */
function bench_rmem_intermediate_report(int $n, string $tmp_out): array
{
    $tmp_rmem = tempnam(sys_get_temp_dir(), 'reli_bench_rmem_') . '.rmem';
    @unlink(substr($tmp_rmem, 0, -5));

    $t0 = microtime(true);
    $bin_out = new BinaryMemoryOutput($tmp_rmem);
    $sink = $bin_out->createStreamingSink();
    emit_tree($sink, $n);
    $summary = [['memory_get_usage' => 1024, 'php_version' => '8.4', 'zend_mm_heap_usage' => 1024]];
    $bin_out->finalizeStreaming($sink, $summary);
    $t_collect = microtime(true) - $t0;

    $t1 = microtime(true);
    $result = new MemoryAnalysisResult(
        summary: $summary,
        context: null,
        pre_populated_rmem_path: $tmp_rmem,
    );
    $rep = ReportMemoryOutput::text(null, $tmp_out);
    $rep->output($result);
    $t_output = microtime(true) - $t1;

    @unlink($tmp_rmem);
    @unlink($tmp_out);
    return ['collect' => $t_collect, 'output' => $t_output, 'total' => $t_collect + $t_output];
}

function median_field(array $samples, string $field): float
{
    $vals = array_map(fn ($s) => $s[$field], $samples);
    sort($vals);
    $c = count($vals);
    return $c % 2 === 1
        ? $vals[intdiv($c, 2)]
        : ($vals[intdiv($c, 2) - 1] + $vals[intdiv($c, 2)]) / 2;
}

$tmp_json = tempnam(sys_get_temp_dir(), 'reli_bench_json_') . '.json';
$tmp_report = tempnam(sys_get_temp_dir(), 'reli_bench_rep_') . '.txt';

$runs = 5;

// Warm-up
bench_rmem_intermediate_json(min(1000, $node_count), $tmp_json);
bench_pdo_intermediate_json(min(1000, $node_count), $tmp_json);

echo "\n=== JSON output ($runs runs each) ===\n";
$pdo_json = [];
for ($i = 0; $i < $runs; $i++) {
    $pdo_json[] = bench_pdo_intermediate_json($node_count, $tmp_json);
    echo sprintf(
        "  pdo  run %d: collect=%.3fs output=%.3fs total=%.3fs\n",
        $i + 1,
        end($pdo_json)['collect'],
        end($pdo_json)['output'],
        end($pdo_json)['total']
    );
}
$rmem_json = [];
for ($i = 0; $i < $runs; $i++) {
    $rmem_json[] = bench_rmem_intermediate_json($node_count, $tmp_json);
    echo sprintf(
        "  rmem run %d: collect=%.3fs output=%.3fs total=%.3fs\n",
        $i + 1,
        end($rmem_json)['collect'],
        end($rmem_json)['output'],
        end($rmem_json)['total']
    );
}

echo "\n=== Report output ($runs runs each) ===\n";
$pdo_report = [];
for ($i = 0; $i < $runs; $i++) {
    $pdo_report[] = bench_pdo_intermediate_report($node_count, $tmp_report);
    echo sprintf(
        "  pdo  run %d: collect=%.3fs output=%.3fs total=%.3fs\n",
        $i + 1,
        end($pdo_report)['collect'],
        end($pdo_report)['output'],
        end($pdo_report)['total']
    );
}
$rmem_report = [];
for ($i = 0; $i < $runs; $i++) {
    $rmem_report[] = bench_rmem_intermediate_report($node_count, $tmp_report);
    echo sprintf(
        "  rmem run %d: collect=%.3fs output=%.3fs total=%.3fs\n",
        $i + 1,
        end($rmem_report)['collect'],
        end($rmem_report)['output'],
        end($rmem_report)['total']
    );
}

echo "\n=== Summary (median of $runs runs) ===\n";
echo "                       collect       output        total       speedup\n";
echo sprintf(
    "JSON   pdo  intermediate: %7.3fs %12.3fs %12.3fs\n",
    median_field($pdo_json, 'collect'),
    median_field($pdo_json, 'output'),
    median_field($pdo_json, 'total')
);
echo sprintf(
    "JSON   rmem intermediate: %7.3fs %12.3fs %12.3fs   %.2fx\n",
    median_field($rmem_json, 'collect'),
    median_field($rmem_json, 'output'),
    median_field($rmem_json, 'total'),
    median_field($pdo_json, 'total') / median_field($rmem_json, 'total')
);
echo sprintf(
    "Report pdo  intermediate: %7.3fs %12.3fs %12.3fs\n",
    median_field($pdo_report, 'collect'),
    median_field($pdo_report, 'output'),
    median_field($pdo_report, 'total')
);
echo sprintf(
    "Report rmem intermediate: %7.3fs %12.3fs %12.3fs   %.2fx\n",
    median_field($rmem_report, 'collect'),
    median_field($rmem_report, 'output'),
    median_field($rmem_report, 'total'),
    median_field($pdo_report, 'total') / median_field($rmem_report, 'total')
);

@unlink($tmp_json);
@unlink($tmp_report);
