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

use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Group;
use Reli\BaseTestCase;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Lib\ByteStream\IntegerByteSequence\LittleEndianReader;
use Reli\Lib\Elf\Parser\Elf64Parser;
use Reli\Lib\Elf\Process\LinkMapLoader;
use Reli\Lib\Elf\Process\PerBinarySymbolCacheRetriever;
use Reli\Lib\Elf\Process\ProcessModuleSymbolReaderCreator;
use Reli\Lib\Elf\SymbolResolver\Elf64SymbolResolverCreator;
use Reli\Lib\File\CatFileReader;
use Reli\Lib\File\PathResolver\ContainerAwarePathResolver;
use Reli\Lib\Libc\Errno\Errno;
use Reli\Lib\Libc\Sys\Ptrace\PtraceX64;
use Reli\Lib\PhpInternals\Opcodes\OpcodeFactory;
use Reli\Lib\PhpInternals\ZendTypeReaderCreator;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader;
use Reli\Lib\PhpProcessReader\CallTraceReader\TraceCache;
use Reli\Lib\PhpProcessReader\CallTraceReader\TraceMerger;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpSymbolReaderCreator;
use Reli\Lib\PhpProcessReader\PhpTsrmLsCacheFinder;
use Reli\Lib\PhpProcessReader\TsrmGlobalsResolver;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreator;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapParser;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapReader;
use Reli\Lib\Process\MemoryReader\MemoryReader;
use Reli\Lib\Process\ProcessSpecifier;
use Reli\Lib\Process\ProcessStopper\ProcessStopper;
use Reli\Lib\String\LineFetcher;
use Reli\TargetPhpVmProvider;

#[Group('target-version')]
class NativeTraceCollectorTest extends BaseTestCase
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

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testNativeTraceCollection(
        string $php_version,
        string $docker_image_name,
    ): void {
        if ($php_version === 'skip') {
            $this->markTestSkipped('no target version');
        }

        $memory_reader = new MemoryReader();
        $target_script =
            <<<CODE
            <?php
            function myFunc() {
                usleep(100000000);
            }
            fputs(STDOUT, "a\n");
            myFunc();
            CODE
        ;
        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes
        );

        $s = fgets($pipes[1]);
        $this->assertSame("a\n", $s);
        $child_status = proc_get_status($this->child);
        $this->assertSame(true, $child_status['running']);

        // Stop the process for GETREGS
        $ptrace = new PtraceX64();
        $process_stopper = new ProcessStopper($ptrace, new Errno());
        $this->assertTrue($process_stopper->stop($pid));

        try {
            // Collect native trace
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
            $collector->refreshMemoryMap($pid);
            $native_trace = $collector->collect($pid);

            // Native trace should be non-null
            $this->assertNotNull(
                $native_trace,
                'Native trace should be collected'
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
                'At least one frame should have a symbol name'
            );

            // Also get PHP trace for merge test
            $integer_reader = new LittleEndianReader();
            $php_symbol_reader_creator = new PhpSymbolReaderCreator(
                new ProcessModuleSymbolReaderCreator(
                    new Elf64SymbolResolverCreator(
                        new CatFileReader(),
                        new Elf64Parser($integer_reader)
                    ),
                    $memory_reader,
                    new PerBinarySymbolCacheRetriever(),
                    $integer_reader,
                    new LinkMapLoader(
                        $memory_reader,
                        $integer_reader
                    ),
                    new ContainerAwarePathResolver(),
                ),
                ProcessMemoryMapCreator::create(),
            );
            $tsrm_globals_resolver = new TsrmGlobalsResolver(
                $php_symbol_reader_creator,
                $integer_reader,
                $memory_reader,
            );
            $tsrm_ls_cache_finder = new PhpTsrmLsCacheFinder(
                $php_symbol_reader_creator,
                $tsrm_globals_resolver,
                $memory_reader,
                $integer_reader,
                new Elf64Parser($integer_reader),
                new CatFileReader(),
                ProcessMemoryMapCreator::create(),
                new ContainerAwarePathResolver(),
                new ZendTypeReaderCreator(),
            );
            $php_globals_finder = new PhpGlobalsFinder(
                $php_symbol_reader_creator,
                $integer_reader,
                $memory_reader,
                $tsrm_ls_cache_finder,
                $tsrm_globals_resolver,
            );
            $eg_address = $php_globals_finder->findExecutorGlobals(
                new ProcessSpecifier($pid),
                new TargetPhpSettings(php_version: $php_version)
            );
            $sg_address = $php_globals_finder->findSAPIGlobals(
                new ProcessSpecifier($pid),
                new TargetPhpSettings(php_version: $php_version)
            );
            $call_trace_reader = new CallTraceReader(
                $memory_reader,
                new ZendTypeReaderCreator(),
                new OpcodeFactory()
            );
            $call_trace = $call_trace_reader->readCallTrace(
                $pid,
                $php_version,
                $eg_address,
                $sg_address,
                PHP_INT_MAX,
                new TraceCache(),
            );

            if ($call_trace !== null) {
                // Test merge
                $merger = new TraceMerger();
                $merged = $merger->merge($native_trace, $call_trace);
                $this->assertGreaterThan(
                    0,
                    count($merged->frames),
                    'Merged trace should have frames'
                );

                // Should have both native and PHP frames
                $has_native = false;
                $has_php = false;
                foreach ($merged->frames as $frame) {
                    if ($frame->isNative()) {
                        $has_native = true;
                    }
                    if ($frame->isPhp()) {
                        $has_php = true;
                    }
                }
                $this->assertTrue(
                    $has_native,
                    'Merged trace should contain native frames'
                );
                $this->assertTrue(
                    $has_php,
                    'Merged trace should contain PHP frames'
                );
            }
        } finally {
            $process_stopper->resume($pid);
        }
    }
}
