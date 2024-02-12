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

namespace Reli\Lib\PhpInternals\Types\Zend;

use Reli\BaseTestCase;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Lib\ByteStream\IntegerByteSequence\LittleEndianReader;
use Reli\Lib\Elf\Parser\Elf64Parser;
use Reli\Lib\Elf\Process\LinkMapLoader;
use Reli\Lib\Elf\Process\PerBinarySymbolCacheRetriever;
use Reli\Lib\Elf\Process\ProcessModuleSymbolReaderCreator;
use Reli\Lib\Elf\SymbolResolver\Elf64SymbolResolverCreator;
use Reli\Lib\File\CatFileReader;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpInternals\ZendTypeReaderCreator;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpSymbolReaderCreator;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreator;
use Reli\Lib\Process\MemoryReader\MemoryReader;
use Reli\Lib\Process\Pointer\Pointer;
use Reli\Lib\Process\Pointer\RemoteProcessDereferencer;
use Reli\Lib\Process\ProcessSpecifier;

class ZendArrayTest extends BaseTestCase
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

    public function testFindByKey()
    {
        $memory_reader = new MemoryReader();
        $type_reader_creator = new ZendTypeReaderCreator();
        $tmp_file = tempnam(sys_get_temp_dir(), 'reli-prof-test');
        file_put_contents(
            $tmp_file,
            <<<CODE
            <?php
            \$_SERVER;
            fputs(STDOUT, "a\n");
            fgets(STDIN);
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
                )
            ),
            ProcessMemoryMapCreator::create(),
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

        $zend_type_reader = $type_reader_creator->create(ZendTypeReader::V81);
        $eg_pointer = new Pointer(
            ZendExecutorGlobals::class,
            $executor_globals_address,
            $zend_type_reader->sizeOf('zend_executor_globals')
        );
        $dereferencer = new RemoteProcessDereferencer(
            $memory_reader,
            new ProcessSpecifier($child_status['pid']),
            new ZendCastedTypeProvider($zend_type_reader),
        );
        $eg = $dereferencer->deref($eg_pointer);
        $server_array_bucket = $eg->symbol_table->findByKey($dereferencer, '_SERVER');
        $this->assertNotNull($server_array_bucket);
        /** @var ZendArray */
        $server_array = $dereferencer->deref($server_array_bucket->val->value->arr);
        $script_name_bucket = $server_array->findByKey($dereferencer, 'SCRIPT_NAME');
        $this->assertNotNull($script_name_bucket);
    }
}
