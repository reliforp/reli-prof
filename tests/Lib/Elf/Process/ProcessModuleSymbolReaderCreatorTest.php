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

namespace Reli\Lib\Elf\Process;

use Mockery;
use Reli\Lib\Elf\Parser\ElfParserException;
use Reli\Lib\Elf\SymbolResolver\Elf64SymbolResolver;
use Reli\Lib\Elf\SymbolResolver\SymbolResolverCreatorInterface;
use Reli\Lib\Process\MemoryMap\ProcessMemoryArea;
use Reli\Lib\Process\MemoryMap\ProcessMemoryAttribute;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMap;
use Reli\Lib\Process\MemoryMap\ProcessModuleMemoryMapInterface;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;
use PHPUnit\Framework\TestCase;

class ProcessModuleSymbolReaderCreatorTest extends TestCase
{
    public function testCreateModuleReaderByNameRegexFallbackToDynamic()
    {
        $symbol_resolver_creator = Mockery::mock(SymbolResolverCreatorInterface::class);
        $symbol_resolver_creator->expects()
            ->createLinearScanResolverFromPath('/proc/1/root/test_module')
            ->andThrow(new ElfParserException());
        $symbol_resolver_creator->expects()
            ->createDynamicResolverFromPath('/proc/1/root/test_module')
            ->andReturns(Mockery::mock(Elf64SymbolResolver::class));
        $memory_reader = Mockery::mock(MemoryReaderInterface::class);
        $symbol_reader_creator = new ProcessModuleSymbolReaderCreator(
            $symbol_resolver_creator,
            $memory_reader
        );
        $process_memory_map = new ProcessMemoryMap([
            new ProcessMemoryArea(
                '0x00000000',
                '0x10000000',
                '0x00000000',
                new ProcessMemoryAttribute(
                    true,
                    true,
                    true,
                    false
                ),
                '/test_module'
            ),
        ]);

        $this->assertInstanceOf(
            ProcessModuleSymbolReader::class,
            $symbol_reader_creator->createModuleReaderByNameRegex(
                1,
                $process_memory_map,
                '\/test_module',
                null
            )
        );
    }

    public function testCreateModuleReaderByNameRegexFallbackToMemory()
    {
        $memory_reader = Mockery::mock(MemoryReaderInterface::class);

        $symbol_resolver_creator = Mockery::mock(SymbolResolverCreatorInterface::class);
        $symbol_resolver_creator->expects()
            ->createLinearScanResolverFromPath('/proc/1/root/test_module')
            ->andThrow(new ElfParserException());
        $symbol_resolver_creator->expects()
            ->createDynamicResolverFromPath('/proc/1/root/test_module')
            ->andThrow(new ElfParserException());

        $symbol_resolver_creator->expects()
            ->createDynamicResolverFromProcessMemory(
                $memory_reader,
                1,
                Mockery::on(
                    function ($actual) {
                        $this->assertInstanceOf(ProcessModuleMemoryMapInterface::class, $actual);
                        return true;
                    }
                )
            )
            ->andReturns(Mockery::mock(Elf64SymbolResolver::class));

        $symbol_reader_creator = new ProcessModuleSymbolReaderCreator(
            $symbol_resolver_creator,
            $memory_reader
        );
        $process_memory_map = new ProcessMemoryMap([
            new ProcessMemoryArea(
                '0x00000000',
                '0x10000000',
                '0x00000000',
                new ProcessMemoryAttribute(
                    true,
                    true,
                    true,
                    false
                ),
                '/test_module'
            ),
        ]);

        $this->assertInstanceOf(
            ProcessModuleSymbolReader::class,
            $symbol_reader_creator->createModuleReaderByNameRegex(
                1,
                $process_memory_map,
                '\/test_module',
                null
            )
        );
    }

    public function testCreateModuleReaderByNameRegexModuleNotFound()
    {
        $symbol_resolver_creator = Mockery::mock(SymbolResolverCreatorInterface::class);
        $memory_reader = Mockery::mock(MemoryReaderInterface::class);
        $symbol_reader_creator = new ProcessModuleSymbolReaderCreator(
            $symbol_resolver_creator,
            $memory_reader
        );
        $process_memory_map = new ProcessMemoryMap([]);

        $this->assertNull(
            $symbol_reader_creator->createModuleReaderByNameRegex(
                1,
                $process_memory_map,
                'regex',
                null
            )
        );
    }
}
