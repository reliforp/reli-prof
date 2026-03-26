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

namespace Reli\Lib\Dwarf;

use Mockery;
use Reli\BaseTestCase;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;

class JitCodeReaderTest extends BaseTestCase
{
    public function testNotLoadedByDefault(): void
    {
        $memory_reader = Mockery::mock(MemoryReaderInterface::class);
        $reader = new JitCodeReader($memory_reader);

        $this->assertFalse($reader->isLoaded());
        $this->assertFalse($reader->hasFdes());
        $this->assertNull($reader->findFdeForAddress(0x1000));
    }

    public function testLoadWithZeroDescriptor(): void
    {
        $memory_reader = Mockery::mock(MemoryReaderInterface::class);

        // Simulate: jit_descriptor.first_entry = 0 (no JIT code registered)
        $zero_bytes = \FFI::new('unsigned char[8]');
        for ($i = 0; $i < 8; $i++) {
            $zero_bytes[$i] = 0;
        }
        $memory_reader->expects('read')
            ->with(123, Mockery::any(), 8)
            ->andReturn($zero_bytes);

        $reader = new JitCodeReader($memory_reader);
        // descriptor + 16 = first_entry pointer
        $reader->load(123, 0x400000);

        $this->assertTrue($reader->isLoaded());
        $this->assertFalse($reader->hasFdes());
    }

    public function testFindFdeEmptyReturnsNull(): void
    {
        $memory_reader = Mockery::mock(MemoryReaderInterface::class);
        $zero = \FFI::new('unsigned char[8]');
        for ($i = 0; $i < 8; $i++) {
            $zero[$i] = 0;
        }
        $memory_reader->allows('read')->andReturn($zero);

        $reader = new JitCodeReader($memory_reader);
        $reader->load(1, 0x1000);

        $this->assertNull($reader->findFdeForAddress(0x5000));
    }

    public function testLoadWithInvalidMemoryGracefullyFails(): void
    {
        $memory_reader = Mockery::mock(MemoryReaderInterface::class);

        // First read gets first_entry pointer (non-zero)
        $ptr_bytes = \FFI::new('unsigned char[8]');
        $ptr_bytes[0] = 0x00;
        $ptr_bytes[1] = 0x10;
        for ($i = 2; $i < 8; $i++) {
            $ptr_bytes[$i] = 0;
        }

        // Subsequent reads throw (simulating invalid memory)
        $memory_reader->expects('read')
            ->once()
            ->andReturn($ptr_bytes);
        $memory_reader->allows('read')
            ->andThrow(new \RuntimeException('memory read failed'));

        $reader = new JitCodeReader($memory_reader);
        $reader->load(1, 0x400000);

        // Should not crash, just have no FDEs
        $this->assertTrue($reader->isLoaded());
        $this->assertFalse($reader->hasFdes());
    }

    public function testLoadWithNonElfDataSkipsEntry(): void
    {
        $memory_reader = Mockery::mock(MemoryReaderInterface::class);

        // first_entry pointer = 0x1000
        $entry_ptr = $this->makePointerBytes(0x1000);
        // symfile_addr = 0x2000
        $symfile_addr = $this->makePointerBytes(0x2000);
        // symfile_size = 64
        $symfile_size = $this->makePointerBytes(64);
        // next = 0 (end of list)
        $zero = $this->makePointerBytes(0);

        // Non-ELF data (not starting with 0x7f ELF)
        $garbage = \FFI::new('unsigned char[64]');
        for ($i = 0; $i < 64; $i++) {
            $garbage[$i] = 0xAA;
        }

        $memory_reader->allows('read')
            ->with(1, Mockery::any(), 8)
            ->andReturnUsing(function ($pid, $addr, $size)
 use ($entry_ptr, $symfile_addr, $symfile_size, $zero) {
                // descriptor + 16 → first_entry
                if ($addr === 0x400010) {
                    return $entry_ptr;
                }
                // entry + 16 → symfile_addr
                if ($addr === 0x1010) {
                    return $symfile_addr;
                }
                // entry + 24 → symfile_size
                if ($addr === 0x1018) {
                    return $symfile_size;
                }
                // entry + 0 → next
                if ($addr === 0x1000) {
                    return $zero;
                }
                return $zero;
            });
        // Read the ELF object (garbage data)
        $memory_reader->allows('read')
            ->with(1, 0x2000, 64)
            ->andReturn($garbage);

        $reader = new JitCodeReader($memory_reader);
        $reader->load(1, 0x400000);

        $this->assertTrue($reader->isLoaded());
        // Non-ELF data should be skipped
        $this->assertFalse($reader->hasFdes());
    }

    /**
     * @return \FFI\CData
     */
    private function makePointerBytes(int $value): \FFI\CData
    {
        $bytes = \FFI::new('unsigned char[8]');
        for ($i = 0; $i < 8; $i++) {
            $bytes[$i] = ($value >> ($i * 8)) & 0xff;
        }
        return $bytes;
    }
}
