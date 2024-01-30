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

use Reli\BaseTestCase;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Lib\ByteStream\IntegerByteSequence\LittleEndianReader;
use Reli\Lib\Elf\Parser\Elf64Parser;
use Reli\Lib\Elf\Process\PerBinarySymbolCacheRetriever;
use Reli\Lib\Elf\Process\ProcessModuleSymbolReaderCreator;
use Reli\Lib\Elf\SymbolResolver\Elf64SymbolResolverCreator;
use Reli\Lib\File\CatFileReader;
use Reli\Lib\PhpInternals\Opcodes\OpcodeFactory;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpInternals\ZendTypeReaderCreator;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpSymbolReaderCreator;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreator;
use Reli\Lib\Process\MemoryReader\MemoryReader;
use Reli\Lib\Process\ProcessSpecifier;

class CallTraceReaderTest extends BaseTestCase
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
        $tmp_file = tempnam(sys_get_temp_dir(), 'reli-prof-test');
        file_put_contents(
            $tmp_file,
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
        );
        $this->child = proc_open(
            [
                PHP_BINARY,
                $tmp_file,
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
                new PerBinarySymbolCacheRetriever(),
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
            ZendTypeReader::V81,
            $executor_globals_address,
            $sapi_globals_address,
            PHP_INT_MAX,
            new TraceCache(),
            false,
        );
        $this->assertCount(3, $call_trace->call_frames);
        $this->assertSame(
            'fgets',
            $call_trace->call_frames[0]->getFullyQualifiedFunctionName()
        );
        $this->assertSame(
            '<internal>',
            $call_trace->call_frames[0]->file_name
        );
        $this->assertSame(
            null,
            $call_trace->call_frames[0]->opline
        );
        $this->assertSame(
            'A::wait',
            $call_trace->call_frames[1]->getFullyQualifiedFunctionName()
        );
        $this->assertSame(
            $tmp_file,
            $call_trace->call_frames[1]->file_name
        );
        $this->assertSame(
            4,
            $call_trace->call_frames[1]->opline->lineno
        );
        $this->assertSame(
            '<main>',
            $call_trace->call_frames[2]->getFullyQualifiedFunctionName()
        );
        $this->assertSame(
            $tmp_file,
            $call_trace->call_frames[2]->file_name
        );
        $this->assertSame(
            10,
            $call_trace->call_frames[2]->opline->lineno
        );
    }
}
