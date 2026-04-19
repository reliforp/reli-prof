<?php // phpcs:ignoreFile

/**
 * Micro-benchmark: isolate FFI::load() / ZendTypeReader construction cost.
 *
 * Hypothesis: HeapStatsReader::read() costs ~2ms because it constructs
 * two fresh ZendTypeReader instances per call, each of which lazily
 * triggers FFI::load() (header parsing) on first method access.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpInternals\ZendTypeReaderCreator;

$iterations = (int)($argv[1] ?? 1000);
echo "iterations: {$iterations}\n\n";

$creator = new ZendTypeReaderCreator();

// Warm: sanity check what a single FFI::load costs.
$t0 = hrtime(true);
$warm = $creator->create('v84');
$warm->sizeOf('zend_mm_chunk');
$t1 = hrtime(true);
printf("first create + sizeOf (cold FFI::load): %.1fμs\n\n", ($t1 - $t0) / 1000);

// Case A: fresh ZendTypeReader every iteration, hit sizeOf (triggers FFI::load lazily).
$times = [];
for ($i = 0; $i < $iterations; $i++) {
    $t0 = hrtime(true);
    $ztr = $creator->create('v84');
    $ztr->sizeOf('zend_mm_chunk');
    $t1 = hrtime(true);
    $times[] = ($t1 - $t0) / 1000;
}
report('A: fresh reader + sizeOf (loads FFI each time)', $times);

// Case B: reuse a single ZendTypeReader.
$ztr_shared = $creator->create('v84');
$ztr_shared->sizeOf('zend_mm_chunk'); // warm
$times = [];
for ($i = 0; $i < $iterations; $i++) {
    $t0 = hrtime(true);
    $ztr_shared->sizeOf('zend_mm_chunk');
    $t1 = hrtime(true);
    $times[] = ($t1 - $t0) / 1000;
}
report('B: shared reader sizeOf (warm)', $times);

// Case C: fresh reader but no method call (just construction).
$times = [];
for ($i = 0; $i < $iterations; $i++) {
    $t0 = hrtime(true);
    $ztr = $creator->create('v84');
    $t1 = hrtime(true);
    $times[] = ($t1 - $t0) / 1000;
}
report('C: construction only (no FFI::load)', $times);

// Case D: fresh reader + readAs (also triggers FFI::load + cast).
$buffer = FFI::cdef()->new('char[8]');
$times = [];
for ($i = 0; $i < $iterations; $i++) {
    $t0 = hrtime(true);
    $ztr = $creator->create('v84');
    try {
        $ztr->readAs('zend_executor_globals *', $buffer);
    } catch (\Throwable) {}
    $t1 = hrtime(true);
    $times[] = ($t1 - $t0) / 1000;
}
report('D: fresh reader + readAs (loads FFI each time)', $times);

// Case E: two fresh readers per iteration (simulates HeapStatsReader::read pattern).
$times = [];
for ($i = 0; $i < $iterations; $i++) {
    $t0 = hrtime(true);
    $ztr1 = $creator->create('v84');
    $ztr1->sizeOf('zend_mm_chunk');
    $ztr2 = $creator->create('v84');
    $ztr2->sizeOf('zend_executor_globals');
    $t1 = hrtime(true);
    $times[] = ($t1 - $t0) / 1000;
}
report('E: 2x fresh reader (HeapStatsReader shape)', $times);

function report(string $label, array $times): void {
    sort($times);
    $n = count($times);
    $avg = array_sum($times) / $n;
    $med = $times[(int)($n / 2)];
    $p95 = $times[(int)($n * 0.95)];
    $p99 = $times[(int)($n * 0.99)];
    printf(
        "  %-50s avg=%7.1fμs  med=%7.1fμs  p95=%7.1fμs  p99=%7.1fμs  min=%7.1fμs\n",
        $label, $avg, $med, $p95, $p99, $times[0]
    );
}
