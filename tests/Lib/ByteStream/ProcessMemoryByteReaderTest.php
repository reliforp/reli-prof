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

namespace Reli\Lib\ByteStream;

use Reli\Lib\Process\MemoryMap\ProcessModuleMemoryMapInterface;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;
use PHPUnit\Framework\TestCase;

class ProcessMemoryByteReaderTest extends TestCase
{
    public function testOffsetExists()
    {
        $memory_reader = \Mockery::mock(MemoryReaderInterface::class);

        $process_memory_map = \Mockery::mock(ProcessModuleMemoryMapInterface::class);
        $process_memory_map->expects()->isInRange(1234)->andReturns(true);
        $process_memory_map->expects()->isInRange(4321)->andReturns(false);

        $reader = new ProcessMemoryByteReader($memory_reader, 1, $process_memory_map);

        $this->assertTrue(isset($reader[1234]));
        $this->assertFalse(isset($reader[4321]));
    }

    public function testOffsetGet()
    {
        $buffer = \FFI::new('unsigned char[8192]');
        $buffer[0] = 0xde;
        $buffer[1] = 0xad;
        $buffer[2] = 0xbe;
        $buffer[3] = 0xef;
        $memory_reader = \Mockery::mock(MemoryReaderInterface::class);
        $memory_reader->expects()->read(1, 0x10000000, 8192)->andReturns($buffer);

        $process_memory_map = \Mockery::mock(ProcessModuleMemoryMapInterface::class);
        $process_memory_map->expects()->isInRange(0x10000000)->andReturns(true);
        $process_memory_map->expects()->isInRange(0x10000001)->andReturns(true);
        $process_memory_map->expects()->isInRange(0x10000002)->andReturns(true);
        $process_memory_map->expects()->isInRange(0x10000003)->andReturns(true);
        $process_memory_map->expects()->getBaseAddress()->andReturns(0x10000000)->times(4);

        $reader = new ProcessMemoryByteReader($memory_reader, 1, $process_memory_map);
        $this->assertSame(0xde, $reader[0x10000000]);
        $this->assertSame(0xad, $reader[0x10000001]);
        $this->assertSame(0xbe, $reader[0x10000002]);
        $this->assertSame(0xef, $reader[0x10000003]);
    }

    public function testOffsetGetOutOfBounds()
    {
        $memory_reader = \Mockery::mock(MemoryReaderInterface::class);

        $process_memory_map = \Mockery::mock(ProcessModuleMemoryMapInterface::class);
        $process_memory_map->expects()->isInRange(0x10000000)->andReturns(false);

        $reader = new ProcessMemoryByteReader($memory_reader, 1, $process_memory_map);
        $this->expectException(\OutOfBoundsException::class);
        $reader[0x10000000];
    }

    public function testOffsetGetFirstPageNotAlignedToBufferSize()
    {
        $buffer = \FFI::new('unsigned char[8192]');
        $buffer[0] = 0xde;
        $buffer[1] = 0xad;
        $buffer[2] = 0xbe;
        $buffer[3] = 0xef;
        $memory_reader = \Mockery::mock(MemoryReaderInterface::class);
        $memory_reader->expects()->read(1, 0x10000010, 8192)->andReturns($buffer);

        $process_memory_map = \Mockery::mock(ProcessModuleMemoryMapInterface::class);
        $process_memory_map->expects()->isInRange(0x10000010)->andReturns(true);
        $process_memory_map->expects()->isInRange(0x10000011)->andReturns(true);
        $process_memory_map->expects()->isInRange(0x10000012)->andReturns(true);
        $process_memory_map->expects()->isInRange(0x10000013)->andReturns(true);
        $process_memory_map->expects()->getBaseAddress()->andReturns(0x10000010)->times(4);

        $reader = new ProcessMemoryByteReader($memory_reader, 1, $process_memory_map);
        $this->assertSame(0xde, $reader[0x10000010]);
        $this->assertSame(0xad, $reader[0x10000011]);
        $this->assertSame(0xbe, $reader[0x10000012]);
        $this->assertSame(0xef, $reader[0x10000013]);
    }

    public function testCreateSliceAsString()
    {
        $buffer = \FFI::new('unsigned char[8192]');
        $buffer[0] = 0xde;
        $buffer[1] = 0xad;
        $buffer[2] = 0xbe;
        $buffer[3] = 0xef;
        $memory_reader = \Mockery::mock(MemoryReaderInterface::class);
        $memory_reader->expects()->read(1, 0x10000000, 8192)->andReturns($buffer);

        $process_memory_map = \Mockery::mock(ProcessModuleMemoryMapInterface::class);
        $process_memory_map->expects()->isInRange(0x10000000)->andReturns(true);
        $process_memory_map->expects()->isInRange(0x10000001)->andReturns(true);
        $process_memory_map->expects()->isInRange(0x10000002)->andReturns(true);
        $process_memory_map->expects()->isInRange(0x10000003)->andReturns(true);
        $process_memory_map->expects()->getBaseAddress()->andReturns(0x10000000)->times(4);

        $reader = new ProcessMemoryByteReader($memory_reader, 1, $process_memory_map);
        $result = $reader->createSliceAsString(0x10000000, 4);

        $this->assertSame(chr(0xde), $result[0]);
        $this->assertSame(chr(0xad), $result[1]);
        $this->assertSame(chr(0xbe), $result[2]);
        $this->assertSame(chr(0xef), $result[3]);
    }
}
