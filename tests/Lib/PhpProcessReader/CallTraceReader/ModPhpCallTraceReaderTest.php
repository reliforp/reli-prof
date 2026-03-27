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

namespace Reli\Lib\PhpProcessReader\CallTraceReader;

use PHPUnit\Framework\Attributes\Group;
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
use Reli\Lib\PhpInternals\Opcodes\OpcodeFactory;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpInternals\ZendTypeReaderCreator;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpSymbolReaderCreator;
use Reli\Lib\PhpProcessReader\PhpTsrmLsCacheFinder;
use Reli\Lib\PhpProcessReader\TsrmGlobalsResolver;
use Reli\Lib\Elf\Process\BinaryFingerprintCreator;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreator;
use Reli\Lib\Process\MemoryReader\MemoryReader;
use Reli\Lib\Process\ProcessSpecifier;
use Reli\TargetPhpVmProvider;

#[Group('mod_php')]
class ModPhpCallTraceReaderTest extends BaseTestCase
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

    public function testReadCallTraceFromModPhpPrefork(): void
    {
        $docker_image_name = 'php:8.3-apache';
        $php_version = ZendTypeReader::V83;

        $memory_reader = new MemoryReader();
        $executor_globals_reader = new CallTraceReader(
            $memory_reader,
            new ZendTypeReaderCreator(),
            new OpcodeFactory()
        );

        // This script blocks on fgets(STDIN) which will never receive input
        // under mod_php, keeping the Apache worker process alive and traceable.
        $target_script =
            <<<'CODE'
            <?php
            class A {
                public function wait() {
                    sleep(120);
                }
            }
            $object = new A;
            $object->wait();
            CODE
        ;
        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaModPhpContainer(
            $docker_image_name,
            $target_script,
            $pipes
        );

        $this->assertGreaterThan(0, $pid, 'Failed to get PID of mod_php worker process');

        $child_status = proc_get_status($this->child);
        $this->assertSame(true, $child_status['running']);

        $target_php_settings = new TargetPhpSettings(
            php_version: $php_version,
        );

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
                $memory_reader,
                new PerBinarySymbolCacheRetriever(),
                new LittleEndianReader(),
                new LinkMapLoader(
                    $memory_reader,
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

        $executor_globals_address = $php_globals_finder->findExecutorGlobals(
            new ProcessSpecifier($pid),
            $target_php_settings,
        );
        $sapi_globals_address = $php_globals_finder->findSAPIGlobals(
            new ProcessSpecifier($pid),
            $target_php_settings,
        );

        $call_trace = $executor_globals_reader->readCallTrace(
            $pid,
            $php_version,
            $executor_globals_address,
            $sapi_globals_address,
            PHP_INT_MAX,
            new TraceCache(),
        );
        $this->assertNotEmpty($call_trace->call_frames);

        // Find the wait() call in the trace
        $function_names = array_map(
            fn ($frame) => $frame->getFullyQualifiedFunctionName(),
            $call_trace->call_frames,
        );
        $this->assertContains(
            'A::wait',
            $function_names,
            'Expected to find A::wait in call trace, got: ' . implode(' -> ', $function_names)
        );
    }
}
