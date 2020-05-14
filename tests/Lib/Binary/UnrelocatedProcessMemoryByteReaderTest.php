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

namespace PhpProfiler\Lib\Binary;

use PhpProfiler\Lib\Process\MemoryMap\ProcessModuleMemoryMapInterface;
use PHPUnit\Framework\TestCase;

class UnrelocatedProcessMemoryByteReaderTest extends TestCase
{
    public function testOffsetExists()
    {
        $module_memory_map = \Mockery::mock(ProcessModuleMemoryMapInterface::class);
        $module_memory_map->expects()->getMemoryAddressFromOffset(0)->andReturns(100);
        $byte_reader = \Mockery::mock(ByteReaderInterface::class);
        $byte_reader->expects()->offsetExists(100)->andReturns(true);

        $reader = new UnrelocatedProcessMemoryByteReader($byte_reader, $module_memory_map);
        $this->assertTrue(isset($reader[0]));
    }

    public function testOffsetGet()
    {
        $module_memory_map = \Mockery::mock(ProcessModuleMemoryMapInterface::class);
        $module_memory_map->expects()->getMemoryAddressFromOffset(0)->andReturns(100);
        $byte_reader = \Mockery::mock(ByteReaderInterface::class);
        $byte_reader->expects()->offsetGet(100)->andReturns(200);

        $reader = new UnrelocatedProcessMemoryByteReader($byte_reader, $module_memory_map);
        $this->assertSame(200, $reader[0]);
    }

    public function testCreateSliceAsString()
    {
        $module_memory_map = \Mockery::mock(ProcessModuleMemoryMapInterface::class);
        $module_memory_map->expects()->getMemoryAddressFromOffset(0)->andReturns(100);
        $byte_reader = \Mockery::mock(ByteReaderInterface::class);
        $byte_reader->expects()->createSliceAsString(100, 8)->andReturns('abcdefgh');

        $reader = new UnrelocatedProcessMemoryByteReader($byte_reader, $module_memory_map);
        $this->assertSame('abcdefgh', $reader->createSliceAsString(0, 8));
    }
}
