<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Lib\PhpProcessReader\PhpMemoryReader;

use PhpProfiler\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use PhpProfiler\Inspector\Settings\TargetProcessSettings\TargetProcessSettings;
use PhpProfiler\Lib\ByteStream\IntegerByteSequence\LittleEndianReader;
use PhpProfiler\Lib\Elf\Parser\Elf64Parser;
use PhpProfiler\Lib\Elf\Process\ProcessModuleSymbolReaderCreator;
use PhpProfiler\Lib\Elf\SymbolResolver\Elf64SymbolResolverCreator;
use PhpProfiler\Lib\File\CatFileReader;
use PhpProfiler\Lib\PhpInternals\Opcodes\OpcodeFactory;
use PhpProfiler\Lib\PhpInternals\Types\Zend\ZendCastedTypeProvider;
use PhpProfiler\Lib\PhpInternals\Types\Zend\ZendExecuteData;
use PhpProfiler\Lib\PhpInternals\Types\Zend\ZendExecutorGlobals;
use PhpProfiler\Lib\PhpInternals\Types\Zend\ZendFunction;
use PhpProfiler\Lib\PhpInternals\Types\Zend\ZendString;
use PhpProfiler\Lib\PhpInternals\ZendTypeReader;
use PhpProfiler\Lib\PhpInternals\ZendTypeReaderCreator;
use PhpProfiler\Lib\PhpProcessReader\TraceCache;
use PhpProfiler\Lib\Process\MemoryMap\ProcessMemoryMapCreator;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReader;
use PhpProfiler\Lib\PhpProcessReader\PhpGlobalsFinder;
use PhpProfiler\Lib\PhpProcessReader\PhpSymbolReaderCreator;
use PhpProfiler\Lib\Process\Pointer\Pointer;
use PhpProfiler\Lib\Process\Pointer\RemoteProcessDereferencer;
use PhpProfiler\Lib\Process\ProcessSpecifier;
use PHPUnit\Framework\TestCase;

class CallTraceReaderTest extends TestCase
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

    public function testReadCallTrace()
    {
        $memory_reader = new MemoryReader();
        $executor_globals_reader = new CallTraceReader(
            $memory_reader,
            new ZendTypeReaderCreator(),
            new OpcodeFactory()
        );
        $this->child = proc_open(
            [
                PHP_BINARY,
                '-r',
                <<<CODE
                class A {
                    public function wait() {
                        fgets(STDIN);
                    }
                }
                \$object = new A;
                fputs(STDOUT, "a\n");
                \$object->wait();
                CODE
            ],
            [
                ['pipe', 'r'],
                ['pipe', 'w'],
                ['pipe', 'w']
            ],
            $pipes
        );

        fgets($pipes[1]);
        $child_status = proc_get_status($this->child);
        $php_symbol_reader_creator = new PhpSymbolReaderCreator(
            $memory_reader,
            new ProcessModuleSymbolReaderCreator(
                new Elf64SymbolResolverCreator(
                    new CatFileReader(),
                    new Elf64Parser(
                        new LittleEndianReader()
                    )
                ),
                $memory_reader,
            ),
            ProcessMemoryMapCreator::create(),
            new LittleEndianReader()
        );
        $php_globals_finder = new PhpGlobalsFinder(
            $php_symbol_reader_creator,
            new LittleEndianReader(),
            new MemoryReader()
        );

        /** @var int $child_status['pid'] */
        $executor_globals_address = $php_globals_finder->findExecutorGlobals(
            new ProcessSpecifier($child_status['pid']),
            new TargetPhpSettings()
        );
        $sapi_globals_address = $php_globals_finder->findSAPIGlobals(
            new ProcessSpecifier($child_status['pid']),
            new TargetPhpSettings()
        );

        $call_trace = $executor_globals_reader->readCallTrace(
            $child_status['pid'],
            ZendTypeReader::V74,
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
}
