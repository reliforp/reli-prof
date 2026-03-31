<?php

/**
 * This file is part of the reliforp/reli-prof package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Reli\Lib\Dwarf;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Reli\BaseTestCase;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Lib\ByteStream\IntegerByteSequence\LittleEndianReader;
use Reli\Lib\Elf\Parser\Elf64Parser;
use Reli\Lib\Elf\Process\BinaryAnalysisCache;
use Reli\Lib\Elf\Process\LinkMapLoader;
use Reli\Lib\Elf\Process\PerBinarySymbolCacheRetriever;
use Reli\Lib\Elf\Process\ProcessModuleSymbolReaderCreator;
use Reli\Lib\Elf\SymbolResolver\Elf64SymbolResolverCreator;
use Reli\Lib\File\CatFileReader;
use Reli\Lib\File\PathResolver\ContainerAwarePathResolver;
use Reli\Lib\Libc\Errno\Errno;
use Reli\Lib\Libc\Sys\Ptrace\PtraceX64;
use Reli\Lib\PhpInternals\Opcodes\OpcodeFactory;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpInternals\ZendTypeReaderCreator;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader;
use Reli\Lib\PhpProcessReader\CallTraceReader\TraceCache;
use Reli\Lib\PhpProcessReader\CallTraceReader\TraceMerger;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpSymbolReaderCreator;
use Reli\Lib\PhpProcessReader\PhpTsrmLsCacheFinder;
use Reli\Lib\PhpProcessReader\TsrmGlobalsResolver;
use Reli\Lib\Elf\Process\BinaryFingerprintCreator;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreator;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapParser;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapReader;
use Reli\Lib\Process\MemoryReader\MemoryReader;
use Reli\Lib\Process\ProcessSpecifier;
use Reli\Lib\Process\ProcessStopper\ProcessStopper;
use Reli\Lib\String\LineFetcher;
use Reli\TargetPhpVmProvider;

#[Group('frankenphp')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class FrankenPhpNativeTraceCollectorTest extends BaseTestCase
{
    /** @var resource|null */
    private $child = null;

    protected function tearDown(): void
    {
        if (!is_null($this->child)) {
            $child_status = proc_get_status($this->child);
            if (is_array($child_status)) {
                if ($child_status['running']) {
                    posix_kill($child_status['pid'], SIGKILL);
                }
            }
        }
    }

    public function testNativeTraceCollectionFromFrankenPhp(): void
    {
        $docker_image_name = 'dunglas/frankenphp:php8.4';
        $php_version = ZendTypeReader::V84;

        $target_script =
            <<<CODE
            <?php
            class A {
                public function wait() {
                    usleep(100000000);
                }
            }
            \$object = new A;
            fputs(STDOUT, "a\n");
            \$object->wait();
            CODE
        ;
        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaFrankenPhpContainer(
            $docker_image_name,
            $target_script,
            $pipes
        );

        $s = fgets($pipes[1]);
        $this->assertSame("a\n", $s);
        $child_status = proc_get_status($this->child);
        $this->assertSame(true, $child_status['running']);

        $target_php_settings = new TargetPhpSettings(
            php_regex: '.*/libphp\.so$',
            libpthread_regex: '.*/libc\.so.*',
            php_version: $php_version,
        );

        // FrankenPHP runs PHP in a worker thread; try all threads to find
        // the one with valid PHP executor globals
        $tids = self::enumerateThreads($pid);
        $this->assertNotEmpty($tids, 'Could not enumerate threads for the FrankenPHP process');

        $php_tid = null;
        $executor_globals_address = null;
        $sapi_globals_address = null;
        $last_error = '';

        foreach ($tids as $tid) {
            try {
                $binary_analysis_cache = new BinaryAnalysisCache(
                    sys_get_temp_dir() . '/reli-test-' . uniqid()
                );
                $process_memory_map_creator = ProcessMemoryMapCreator::create();
                $php_symbol_reader_creator = new PhpSymbolReaderCreator(
                    new ProcessModuleSymbolReaderCreator(
                        new Elf64SymbolResolverCreator(
                            new CatFileReader(),
                            new Elf64Parser(
                                new LittleEndianReader()
                            )
                        ),
                        new MemoryReader(),
                        new PerBinarySymbolCacheRetriever(),
                        new LittleEndianReader(),
                        new LinkMapLoader(
                            new MemoryReader(),
                            new LittleEndianReader()
                        ),
                        new ContainerAwarePathResolver(),
                        $binary_analysis_cache,
                    ),
                    $process_memory_map_creator,
                    $binary_analysis_cache,
                );
                $integer_reader = new LittleEndianReader();
                $memory_reader_for_finder = new MemoryReader();
                $binary_fingerprint_creator = new BinaryFingerprintCreator($memory_reader_for_finder);
                $tsrm_globals_resolver = new TsrmGlobalsResolver(
                    $php_symbol_reader_creator,
                    $integer_reader,
                    $memory_reader_for_finder,
                    $binary_analysis_cache,
                    $process_memory_map_creator,
                    $binary_fingerprint_creator,
                );
                $tsrm_ls_cache_finder = new PhpTsrmLsCacheFinder(
                    $php_symbol_reader_creator,
                    $tsrm_globals_resolver,
                    $memory_reader_for_finder,
                    $integer_reader,
                    new Elf64Parser($integer_reader),
                    new CatFileReader(),
                    ProcessMemoryMapCreator::create(),
                    new ContainerAwarePathResolver(),
                    new ZendTypeReaderCreator(),
                    $binary_analysis_cache,
                    $binary_fingerprint_creator,
                );
                $php_globals_finder = new PhpGlobalsFinder(
                    $php_symbol_reader_creator,
                    $integer_reader,
                    $memory_reader_for_finder,
                    $tsrm_ls_cache_finder,
                    $tsrm_globals_resolver,
                    $binary_analysis_cache,
                    $process_memory_map_creator,
                    $binary_fingerprint_creator,
                );

                $eg_addr = $php_globals_finder->findExecutorGlobals(
                    new ProcessSpecifier($tid),
                    $target_php_settings,
                );
                $sg_addr = $php_globals_finder->findSAPIGlobals(
                    new ProcessSpecifier($tid),
                    $target_php_settings,
                );

                $php_tid = $tid;
                $executor_globals_address = $eg_addr;
                $sapi_globals_address = $sg_addr;
                break;
            } catch (\Throwable $e) {
                $last_error = "TID {$tid}: " . get_class($e) . ': ' . $e->getMessage();
                continue;
            }
        }

        $this->assertNotNull(
            $php_tid,
            "Could not find a thread with valid PHP executor globals.\n"
            . "pid={$pid}, tids=[" . implode(',', $tids) . "]\n"
            . "last_error: {$last_error}"
        );

        // Stop the PHP worker thread for GETREGS
        $ptrace = new PtraceX64();
        $process_stopper = new ProcessStopper($ptrace, new Errno());
        $this->assertTrue($process_stopper->stop($php_tid));

        try {
            // Collect native trace
            $memory_reader = new MemoryReader();
            $map_reader = new ProcessMemoryMapReader(
                new CatFileReader()
            );
            $map_parser = new ProcessMemoryMapParser(
                new LineFetcher()
            );
            $collector = new NativeTraceCollector(
                $ptrace,
                $memory_reader,
                $map_reader,
                $map_parser,
            );
            $collector->refreshMemoryMap($php_tid);
            $native_trace = $collector->collect($php_tid);

            // Native trace should be non-null
            $this->assertNotNull(
                $native_trace,
                'Native trace should be collected from FrankenPHP worker thread'
            );
            $this->assertGreaterThan(
                0,
                count($native_trace->frames),
                'Should have at least one native frame'
            );

            // Should contain at least one frame with a resolved symbol name
            $symbol_names = array_filter(
                array_map(
                    fn(NativeFrame $f) => $f->symbolName,
                    $native_trace->frames,
                )
            );
            $this->assertNotEmpty(
                $symbol_names,
                'At least one frame should have a symbol name resolved'
            );

            // Log frame details for debugging
            $frame_details = [];
            foreach ($native_trace->frames as $i => $frame) {
                $frame_details[] = sprintf(
                    '#%d %s+0x%x (%s)',
                    $i,
                    $frame->symbolName ?? '??',
                    $frame->offset,
                    $frame->moduleName ?? '??',
                );
            }
            fwrite(STDERR, "\n=== FrankenPHP Native Trace ===\n");
            fwrite(STDERR, implode("\n", $frame_details) . "\n");
            fwrite(STDERR, "=== End Native Trace ===\n\n");

            // Also get PHP trace and test merge
            $call_trace_reader = new CallTraceReader(
                $memory_reader,
                new ZendTypeReaderCreator(),
                new OpcodeFactory()
            );
            $call_trace = $call_trace_reader->readCallTrace(
                $php_tid,
                $php_version,
                $executor_globals_address,
                $sapi_globals_address,
                PHP_INT_MAX,
                new TraceCache(),
            );

            if ($call_trace !== null) {
                // Log PHP trace for debugging
                $php_frames = [];
                foreach ($call_trace->call_frames as $i => $frame) {
                    $php_frames[] = sprintf(
                        '#%d %s',
                        $i,
                        $frame->getFullyQualifiedFunctionName(),
                    );
                }
                fwrite(STDERR, "\n=== FrankenPHP PHP Trace ===\n");
                fwrite(STDERR, implode("\n", $php_frames) . "\n");
                fwrite(STDERR, "=== End PHP Trace ===\n\n");

                // Test merge
                $merger = new TraceMerger();
                $merged = $merger->merge($native_trace, $call_trace);
                $this->assertGreaterThan(
                    0,
                    count($merged->frames),
                    'Merged trace should have frames'
                );

                // Log merged trace
                $merged_details = [];
                foreach ($merged->frames as $i => $frame) {
                    $merged_details[] = sprintf(
                        '#%d [%s] %s',
                        $i,
                        $frame->isNative() ? 'native' : 'php',
                        $frame->isNative()
                            ? ($frame->nativeFrame->symbol_name ?? '??')
                            : $frame->phpFrame->getFullyQualifiedFunctionName(),
                    );
                }
                fwrite(STDERR, "\n=== FrankenPHP Merged Trace ===\n");
                fwrite(STDERR, implode("\n", $merged_details) . "\n");
                fwrite(STDERR, "=== End Merged Trace ===\n\n");
            }
        } finally {
            $process_stopper->resume($php_tid);
        }
    }

    /**
     * Enumerate all thread IDs for the process group containing the given PID/TID.
     * @return int[]
     */
    private static function enumerateThreads(int $pid): array
    {
        $tgid = self::findTgidFromTid($pid);
        $task_dir = "/proc/{$tgid}/task";
        if (!is_dir($task_dir)) {
            return [];
        }
        $tids = scandir($task_dir);
        if ($tids === false) {
            return [];
        }
        $result = [];
        foreach ($tids as $tid) {
            if ($tid === '.' || $tid === '..') {
                continue;
            }
            $result[] = (int)$tid;
        }
        return $result;
    }

    private static function findTgidFromTid(int $tid): int
    {
        $status = @file_get_contents("/proc/{$tid}/status");
        if ($status !== false && preg_match('/^Tgid:\s+(\d+)/m', $status, $matches)) {
            return (int)$matches[1];
        }
        return $tid;
    }
}
