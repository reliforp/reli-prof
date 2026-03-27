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
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreator;
use Reli\Lib\Process\MemoryReader\MemoryReader;
use Reli\Lib\Process\ProcessSpecifier;
use Reli\TargetPhpVmProvider;

#[Group('frankenphp')]
class FrankenPhpCallTraceReaderTest extends BaseTestCase
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

    public function testReadCallTraceFromFrankenPhp(): void
    {
        $docker_image_name = 'dunglas/frankenphp:php8.4';
        $php_version = ZendTypeReader::V84;

        $memory_reader = new MemoryReader();
        $executor_globals_reader = new CallTraceReader(
            $memory_reader,
            new ZendTypeReaderCreator(),
            new OpcodeFactory()
        );
        $target_script =
            <<<CODE
            <?php
            class A {
                public function wait() {
                    fgets(STDIN);
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
                $tsrm_globals_resolver = new TsrmGlobalsResolver(
                    $php_symbol_reader_creator,
                    $integer_reader,
                    $memory_reader_for_finder,
                    $binary_analysis_cache,
                    $process_memory_map_creator,
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
                );
                $php_globals_finder = new PhpGlobalsFinder(
                    $php_symbol_reader_creator,
                    $integer_reader,
                    $memory_reader_for_finder,
                    $tsrm_ls_cache_finder,
                    $tsrm_globals_resolver,
                    $binary_analysis_cache,
                    $process_memory_map_creator,
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

        $call_trace = $executor_globals_reader->readCallTrace(
            $php_tid,
            $php_version,
            $executor_globals_address,
            $sapi_globals_address,
            PHP_INT_MAX,
            new TraceCache(),
        );
        $this->assertCount(3, $call_trace->call_frames);
        $this->assertSame(
            'fgets',
            $call_trace->call_frames[0]->getFullyQualifiedFunctionName()
        );
        $this->assertSame(
            'A::wait',
            $call_trace->call_frames[1]->getFullyQualifiedFunctionName()
        );
        $this->assertSame(
            '<main>',
            $call_trace->call_frames[2]->getFullyQualifiedFunctionName()
        );
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
