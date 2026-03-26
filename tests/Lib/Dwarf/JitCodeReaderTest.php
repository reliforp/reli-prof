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
}
