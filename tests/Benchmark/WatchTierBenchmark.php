<?php

/**
 * Benchmark: Watch trigger polling overhead per tier.
 *
 * Measures wall-clock time of each tier's per-poll operations
 * against a real PHP process running in a Docker container.
 *
 * Usage: php tests/Benchmark/WatchTierBenchmark.php [php_version] [docker_image]
 *   e.g.: php tests/Benchmark/WatchTierBenchmark.php v84 php:8.4-cli
 */

require __DIR__ . '/../../vendor/autoload.php';

use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Inspector\Watch\HeapStatsReader;
use Reli\Inspector\Watch\VariableReader;
use Reli\Inspector\Watch\Trigger\MemoryLimitTrigger;
use Reli\Inspector\Watch\Trigger\FunctionDetectionTrigger;
use Reli\Inspector\Watch\Trigger\VariableValueTrigger;
use Reli\Inspector\Watch\HeapStats;
use Reli\Inspector\Watch\WatchContext;
use Reli\Lib\ByteStream\IntegerByteSequence\LittleEndianReader;
use Reli\Lib\Elf\Parser\Elf64Parser;
use Reli\Lib\Elf\Process\BinaryAnalysisCache;
use Reli\Lib\Elf\Process\BinaryFingerprintCreator;
use Reli\Lib\Elf\Process\LinkMapLoader;
use Reli\Lib\Elf\Process\PerBinarySymbolCacheRetriever;
use Reli\Lib\Elf\Process\ProcessModuleSymbolReaderCreator;
use Reli\Lib\Elf\SymbolResolver\Elf64SymbolResolverCreator;
use Reli\Lib\File\CatFileReader;
use Reli\Lib\File\PathResolver\ContainerAwarePathResolver;
use Reli\Lib\PhpInternals\Opcodes\OpcodeFactory;
use Reli\Lib\PhpInternals\ZendTypeReaderCreator;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader;
use Reli\Lib\PhpProcessReader\CallTraceReader\TraceCache;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpSymbolReaderCreator;
use Reli\Lib\PhpProcessReader\PhpTsrmLsCacheFinder;
use Reli\Lib\PhpProcessReader\PhpZendMemoryManagerChunkFinder;
use Reli\Lib\PhpProcessReader\TsrmGlobalsResolver;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreator;
use Reli\Lib\Process\MemoryReader\MemoryReader;
use Reli\Lib\Process\ProcessSpecifier;
use Reli\TargetPhpVmProvider;

$php_version = $argv[1] ?? 'v84';
$docker_image = $argv[2] ?? 'php:8.4-cli';
$iterations = (int)($argv[3] ?? 1000);

echo "=== Watch Tier Benchmark ===\n";
echo "Target: {$docker_image} ({$php_version})\n";
echo "Iterations: {$iterations}\n\n";

// Start target process
$target_script = <<<'CODE'
<?php
$GLOBALS['counter'] = 42;
$GLOBALS['cache'] = array_fill(0, 100, 'item');
function worker() {
    static $count = 0;
    $count++;
    fputs(STDOUT, "ready\n");
    while (true) {
        usleep(1000);
    }
}
worker();
CODE;

$pipes = [];
[$child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
    $docker_image,
    $target_script,
    $pipes,
);
$s = fgets($pipes[1]);
if ($s !== "ready\n") {
    fwrite(STDERR, "Target failed to start: $s\n");
    exit(1);
}
echo "Target PID: {$pid}\n\n";

// Setup
$mr = new MemoryReader();
$ztrc = new ZendTypeReaderCreator();
$ir = new LittleEndianReader();
$bac = new BinaryAnalysisCache(sys_get_temp_dir() . '/reli-bench-' . uniqid());
$pmc = ProcessMemoryMapCreator::create();
$bfc = new BinaryFingerprintCreator($mr);
$psrc = new PhpSymbolReaderCreator(
    new ProcessModuleSymbolReaderCreator(
        new Elf64SymbolResolverCreator(new CatFileReader(), new Elf64Parser($ir)),
        $mr,
        new PerBinarySymbolCacheRetriever(),
        $ir,
        new LinkMapLoader($mr, $ir),
        new ContainerAwarePathResolver(),
        $bac,
    ),
    $pmc,
    $bac,
);
$tgr = new TsrmGlobalsResolver($psrc, $ir, $mr, $bac, $pmc, $bfc);
$finder = new PhpGlobalsFinder(
    $psrc,
    $ir,
    $mr,
    new PhpTsrmLsCacheFinder(
        $psrc,
        $tgr,
        $mr,
        $ir,
        new Elf64Parser($ir),
        new CatFileReader(),
        ProcessMemoryMapCreator::create(),
        new ContainerAwarePathResolver(),
        $ztrc,
        $bac,
        $bfc,
    ),
    $tgr,
    $bac,
    $pmc,
    $bfc,
);

$ps = new ProcessSpecifier($pid);
$tps = new TargetPhpSettings(php_version: $php_version);
$eg_address = $finder->findExecutorGlobals($ps, $tps);
$sg_address = $finder->findSAPIGlobals($ps, $tps);
$cg_address = $finder->findCompilerGlobals($ps, $tps);

$chunk_finder = new PhpZendMemoryManagerChunkFinder($pmc, $ztrc);
$heap_reader = new HeapStatsReader($chunk_finder, $mr, $ztrc);
$call_trace_reader = new CallTraceReader($mr, $ztrc, new OpcodeFactory());
$variable_reader = new VariableReader($mr, $ztrc);

echo "Setup complete. Starting benchmarks...\n\n";

// --- Tier 1: HeapStats only ---
echo "--- Tier 1: HeapStats read ---\n";
$times = [];
for ($i = 0; $i < $iterations; $i++) {
    $t0 = hrtime(true);
    $stats = $heap_reader->read($ps, $tps, $eg_address);
    $t1 = hrtime(true);
    $times[] = ($t1 - $t0) / 1000; // microseconds
}
reportTimes('HeapStatsReader::read()', $times);

// Tier 1: Trigger evaluation (pure CPU, no I/O)
$trigger_mem = new MemoryLimitTrigger(999999999);
$ctx = new WatchContext(
    pid: $pid,
    heap_stats: $stats,
    call_trace: null,
    timestamp: microtime(true),
    previous: null,
);
$times = [];
for ($i = 0; $i < $iterations; $i++) {
    $t0 = hrtime(true);
    $trigger_mem->evaluate($ctx);
    $t1 = hrtime(true);
    $times[] = ($t1 - $t0) / 1000;
}
reportTimes('MemoryLimitTrigger::evaluate()', $times);

// --- Tier 1: hasException ---
echo "\n--- Tier 1+: hasException check ---\n";
$times = [];
for ($i = 0; $i < $iterations; $i++) {
    $t0 = hrtime(true);
    $heap_reader->hasException($ps, $tps, $eg_address);
    $t1 = hrtime(true);
    $times[] = ($t1 - $t0) / 1000;
}
reportTimes('HeapStatsReader::hasException()', $times);

// --- Tier 2: Call trace ---
echo "\n--- Tier 2: Call trace read ---\n";
$trace_cache = new TraceCache();
$times = [];
for ($i = 0; $i < $iterations; $i++) {
    $t0 = hrtime(true);
    $trace = $call_trace_reader->readCallTrace(
        $pid,
        $php_version,
        $eg_address,
        $sg_address,
        PHP_INT_MAX,
        $trace_cache,
    );
    $t1 = hrtime(true);
    $times[] = ($t1 - $t0) / 1000;
}
reportTimes('CallTraceReader::readCallTrace()', $times);

// Tier 2: Trigger evaluation
if ($trace !== null) {
    $trigger_func = new FunctionDetectionTrigger('worker');
    $ctx2 = new WatchContext(
        pid: $pid,
        heap_stats: $stats,
        call_trace: $trace,
        timestamp: microtime(true),
        previous: null,
    );
    $times = [];
    for ($i = 0; $i < $iterations; $i++) {
        $t0 = hrtime(true);
        $trigger_func->evaluate($ctx2);
        $t1 = hrtime(true);
        $times[] = ($t1 - $t0) / 1000;
    }
    reportTimes('FunctionDetectionTrigger::evaluate()', $times);
}

// --- Tier 3: Variable reading ---
echo "\n--- Tier 3: Variable read (global) ---\n";
$var_triggers = [new VariableValueTrigger('global::$counter:gt:0')];
$times = [];
for ($i = 0; $i < $iterations; $i++) {
    $t0 = hrtime(true);
    $variable_reader->readVariables(
        $var_triggers,
        $ps,
        $tps,
        $eg_address,
        $cg_address,
    );
    $t1 = hrtime(true);
    $times[] = ($t1 - $t0) / 1000;
}
reportTimes('VariableReader::readVariables(global)', $times);

echo "\n--- Tier 3: Variable read (global array) ---\n";
$var_triggers_arr = [new VariableValueTrigger('global::$cache:count_gt:0')];
$times = [];
for ($i = 0; $i < $iterations; $i++) {
    $t0 = hrtime(true);
    $variable_reader->readVariables(
        $var_triggers_arr,
        $ps,
        $tps,
        $eg_address,
        $cg_address,
    );
    $t1 = hrtime(true);
    $times[] = ($t1 - $t0) / 1000;
}
reportTimes('VariableReader::readVariables(global array)', $times);

// --- Full poll simulation ---
echo "\n--- Full poll: Tier 1 only ---\n";
$times = [];
for ($i = 0; $i < $iterations; $i++) {
    $t0 = hrtime(true);
    $s = $heap_reader->read($ps, $tps, $eg_address);
    $c = new WatchContext($pid, $s, null, null, microtime(true), null);
    $trigger_mem->evaluate($c);
    $t1 = hrtime(true);
    $times[] = ($t1 - $t0) / 1000;
}
reportTimes('Full poll (Tier 1)', $times);

echo "\n--- Full poll: Tier 1 + Tier 2 ---\n";
$times = [];
for ($i = 0; $i < $iterations; $i++) {
    $t0 = hrtime(true);
    $s = $heap_reader->read($ps, $tps, $eg_address);
    $t = $call_trace_reader->readCallTrace(
        $pid,
        $php_version,
        $eg_address,
        $sg_address,
        PHP_INT_MAX,
        $trace_cache,
    );
    $c = new WatchContext($pid, $s, $t, null, microtime(true), null);
    $trigger_mem->evaluate($c);
    $trigger_func->evaluate($c);
    $t1 = hrtime(true);
    $times[] = ($t1 - $t0) / 1000;
}
reportTimes('Full poll (Tier 1+2)', $times);

echo "\n--- Full poll: Tier 1 + 2 + 3 ---\n";
$times = [];
for ($i = 0; $i < $iterations; $i++) {
    $t0 = hrtime(true);
    $s = $heap_reader->read($ps, $tps, $eg_address);
    $exc = $heap_reader->hasException($ps, $tps, $eg_address);
    $t = $call_trace_reader->readCallTrace(
        $pid,
        $php_version,
        $eg_address,
        $sg_address,
        PHP_INT_MAX,
        $trace_cache,
    );
    $vars = $variable_reader->readVariables(
        $var_triggers,
        $ps,
        $tps,
        $eg_address,
        $cg_address,
    );
    $c = new WatchContext($pid, $s, $t, $exc, microtime(true), null, $vars);
    $trigger_mem->evaluate($c);
    $trigger_func->evaluate($c);
    $t1 = hrtime(true);
    $times[] = ($t1 - $t0) / 1000;
}
reportTimes('Full poll (Tier 1+2+3)', $times);

// Cleanup
fclose($pipes[0]);
$child_status = proc_get_status($child);
if ($child_status['running']) {
    posix_kill($child_status['pid'], SIGKILL);
}

function reportTimes(string $label, array $times): void
{
    sort($times);
    $count = count($times);
    $sum = array_sum($times);
    $avg = $sum / $count;
    $median = $times[(int)($count / 2)];
    $p95 = $times[(int)($count * 0.95)];
    $p99 = $times[(int)($count * 0.99)];
    $min = $times[0];
    $max = $times[$count - 1];

    printf(
        "  %-45s avg=%7.1fμs  med=%7.1fμs  p95=%7.1fμs  p99=%7.1fμs  min=%7.1fμs  max=%7.1fμs\n",
        $label,
        $avg,
        $median,
        $p95,
        $p99,
        $min,
        $max,
    );
}
