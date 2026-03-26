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

        // FrankenPHP runs PHP in a separate thread; find it
        $php_tid = self::findPhpThread($pid);
        $this->assertNotNull($php_tid, 'Could not find the PHP thread in the FrankenPHP process');

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
            ),
            ProcessMemoryMapCreator::create(),
        );
        $memory_reader_for_finder = new MemoryReader();
        $integer_reader = new LittleEndianReader();
        $tsrm_globals_resolver = new TsrmGlobalsResolver(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
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
        );
        $php_globals_finder = new PhpGlobalsFinder(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $tsrm_ls_cache_finder,
            $tsrm_globals_resolver,
        );

        $target_php_settings = new TargetPhpSettings(
            php_regex: '.*/libphp\.so$',
            libpthread_regex: '.*/libc\.so.*',
            php_version: $php_version,
        );

        $executor_globals_address = $php_globals_finder->findExecutorGlobals(
            new ProcessSpecifier($php_tid),
            $target_php_settings,
        );
        $sapi_globals_address = $php_globals_finder->findSAPIGlobals(
            new ProcessSpecifier($php_tid),
            $target_php_settings,
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
     * Find the thread ID that is running PHP code in a FrankenPHP process.
     * FrankenPHP (Go) creates multiple threads; we need to find the one executing PHP.
     */
    private static function findPhpThread(int $pid): ?int
    {
        $tgid = self::findTgidFromTid($pid);
        $task_dir = "/proc/{$tgid}/task";
        if (!is_dir($task_dir)) {
            return null;
        }
        $tids = scandir($task_dir);
        if ($tids === false) {
            return null;
        }
        foreach ($tids as $tid) {
            if ($tid === '.' || $tid === '..') {
                continue;
            }
            $tid = (int)$tid;
            // Check if this thread is blocked on read (syscall 0 = read)
            // which matches fgets(STDIN) in our test script
            $syscall_file = "/proc/{$tgid}/task/{$tid}/syscall";
            $syscall_data = @file_get_contents($syscall_file);
            if ($syscall_data === false) {
                continue;
            }
            $parts = explode(' ', trim($syscall_data));
            $syscall_nr = (int)$parts[0];
            // syscall 0 = read (fgets on STDIN)
            if ($syscall_nr === 0) {
                return $tid;
            }
        }
        return null;
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
