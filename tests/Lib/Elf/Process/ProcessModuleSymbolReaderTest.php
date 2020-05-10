<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpProfiler\Lib\Elf\Process;

use FFI;
use Mockery;
use PhpProfiler\Lib\Elf\Structure\Elf64\Elf64SymbolTableEntry;
use PhpProfiler\Lib\Elf\SymbolResolver\Elf64SymbolResolver;
use PhpProfiler\Lib\Process\MemoryMap\ProcessMemoryArea;
use PhpProfiler\Lib\Process\MemoryMap\ProcessMemoryAttribute;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderInterface;
use PhpProfiler\Lib\UInt64;
use PHPUnit\Framework\TestCase;

class ProcessModuleSymbolReaderTest extends TestCase
{
    public function testRead()
    {
        $memory_areas = [
            new ProcessMemoryArea(
                '0x10000000',
                '0x20000000',
                '0x00000000',
                new ProcessMemoryAttribute(
                    true,
                    false,
                    true,
                    true
                ),
                'test_area'
            )
        ];
        $symbol_resolver = Mockery::mock(Elf64SymbolResolver::class);
        $symbol_resolver->expects()
            ->resolve('test_symbol')
            ->andReturns(new Elf64SymbolTableEntry(
                1,
                Elf64SymbolTableEntry::createInfo(
                    Elf64SymbolTableEntry::STB_GLOBAL,
                    Elf64SymbolTableEntry::STT_OBJECT
                ),
                Elf64SymbolTableEntry::STV_DEFAULT,
                1,
                new UInt64(0, 0x1000),
                new UInt64(0, 8),
            ));
        $return = FFI::new('unsigned char[8]');
        $memory_reader = Mockery::mock(MemoryReaderInterface::class);
        $memory_reader->expects()->read(1, 0x10001000, 8)->andReturns($return);

        $process_symbol_reader = new ProcessModuleSymbolReader(
            1,
            $symbol_resolver,
            $memory_areas,
            $memory_reader,
            null
        );
        $this->assertSame(
            $return,
            $process_symbol_reader->read('test_symbol')
        );
    }

    public function testReturnNullOnTryingToReadUndefinedSymbol()
    {
        $memory_areas = [
            new ProcessMemoryArea(
                '0x10000000',
                '0x20000000',
                '0x00000000',
                new ProcessMemoryAttribute(
                    true,
                    false,
                    true,
                    true
                ),
                'test_area'
            )
        ];
        $symbol_resolver = Mockery::mock(Elf64SymbolResolver::class);
        $symbol_resolver->expects()
            ->resolve('test_symbol')
            ->andReturns(new Elf64SymbolTableEntry(
                0,
                Elf64SymbolTableEntry::createInfo(
                    Elf64SymbolTableEntry::STB_LOCAL,
                    Elf64SymbolTableEntry::STT_NOTYPE
                ),
                Elf64SymbolTableEntry::STV_DEFAULT,
                0,
                new UInt64(0, 0),
                new UInt64(0, 0),
            ));
        $memory_reader = Mockery::mock(MemoryReaderInterface::class);

        $process_symbol_reader = new ProcessModuleSymbolReader(
            1,
            $symbol_resolver,
            $memory_areas,
            $memory_reader,
            null
        );
        $this->assertSame(
            null,
            $process_symbol_reader->read('test_symbol')
        );
    }

    public function testResolveAddress()
    {
        $memory_areas = [
            new ProcessMemoryArea(
                '0x10000000',
                '0x20000000',
                '0x00000000',
                new ProcessMemoryAttribute(
                    true,
                    false,
                    true,
                    true
                ),
                'test_area'
            )
        ];
        $symbol_resolver = Mockery::mock(Elf64SymbolResolver::class);
        $symbol_resolver->expects()
            ->resolve('test_symbol')
            ->andReturns(new Elf64SymbolTableEntry(
                1,
                Elf64SymbolTableEntry::createInfo(
                    Elf64SymbolTableEntry::STB_GLOBAL,
                    Elf64SymbolTableEntry::STT_OBJECT
                ),
                Elf64SymbolTableEntry::STV_DEFAULT,
                1,
                new UInt64(0, 0x1000),
                new UInt64(0, 8),
            ));
        $memory_reader = Mockery::mock(MemoryReaderInterface::class);

        $process_symbol_reader = new ProcessModuleSymbolReader(
            1,
            $symbol_resolver,
            $memory_areas,
            $memory_reader,
            null
        );
        $address = $process_symbol_reader->resolveAddress('test_symbol');
        $this->assertSame(0x10001000, $address);
    }
}
