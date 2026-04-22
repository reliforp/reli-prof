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

namespace Reli\Lib\Process\MemoryReader;

use FFI;
use Reli\Lib\FFI\FFIHelper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class BufferedMemoryReaderTest extends TestCase
{
    private function createMockReader(): MemoryReaderInterface&MockMemoryReader
    {
        return new MockMemoryReader();
    }

    #[Test]
    public function testDelegatesToInnerWhenNoBuffer(): void
    {
        $mock = $this->createMockReader();
        $mock->preload(0x1000, 'HELLO');

        $buffered = new BufferedMemoryReader($mock);
        $result = $buffered->read(1, 0x1000, 5);

        $this->assertSame('HELLO', FFI::string($result, 5));
        $this->assertSame(1, $mock->readCount);
    }

    #[Test]
    public function testServesFromBufferAfterPrefetch(): void
    {
        $mock = $this->createMockReader();
        $mock->preload(0x1000, 'ABCDEFGHIJKLMNOP');

        $buffered = new BufferedMemoryReader($mock);
        $buffered->prefetch(1, 0x1000, 16);

        // This read falls within the prefetched range - should NOT call inner
        $mock->readCount = 0;
        $result = $buffered->read(1, 0x1004, 4);

        $this->assertSame('EFGH', FFI::string($result, 4));
        $this->assertSame(0, $mock->readCount);
    }

    #[Test]
    public function testServesFromBufferAtBoundaries(): void
    {
        $mock = $this->createMockReader();
        $mock->preload(0x1000, 'ABCDEFGH');

        $buffered = new BufferedMemoryReader($mock);
        $buffered->prefetch(1, 0x1000, 8);
        $mock->readCount = 0;

        // Read at start
        $result = $buffered->read(1, 0x1000, 2);
        $this->assertSame('AB', FFI::string($result, 2));

        // Read at end
        $result = $buffered->read(1, 0x1006, 2);
        $this->assertSame('GH', FFI::string($result, 2));

        // Read entire range
        $result = $buffered->read(1, 0x1000, 8);
        $this->assertSame('ABCDEFGH', FFI::string($result, 8));

        $this->assertSame(0, $mock->readCount);
    }

    #[Test]
    public function testFallsThroughForOutOfRangeReads(): void
    {
        $mock = $this->createMockReader();
        $mock->preload(0x1000, 'ABCDEFGH');
        $mock->preload(0x2000, 'REMOTE');

        $buffered = new BufferedMemoryReader($mock);
        $buffered->prefetch(1, 0x1000, 8);
        $mock->readCount = 0;

        // Read outside buffer range
        $result = $buffered->read(1, 0x2000, 6);
        $this->assertSame('REMOTE', FFI::string($result, 6));
        $this->assertSame(1, $mock->readCount);
    }

    #[Test]
    public function testFallsThroughForPartialOverlap(): void
    {
        $mock = $this->createMockReader();
        $mock->preload(0x1000, 'ABCDEFGH');
        $mock->preload(0x1006, 'GHIJKL');

        $buffered = new BufferedMemoryReader($mock);
        $buffered->prefetch(1, 0x1000, 8);
        $mock->readCount = 0;

        // This read extends past the buffer - should fall through
        $result = $buffered->read(1, 0x1006, 6);
        $this->assertSame('GHIJKL', FFI::string($result, 6));
        $this->assertSame(1, $mock->readCount);
    }

    #[Test]
    public function testFallsThroughForDifferentPid(): void
    {
        $mock = $this->createMockReader();
        $mock->preload(0x1000, 'ABCDEFGH');

        $buffered = new BufferedMemoryReader($mock);
        $buffered->prefetch(1, 0x1000, 8);
        $mock->readCount = 0;

        // Different PID - should fall through
        $result = $buffered->read(2, 0x1000, 8);
        $this->assertSame('ABCDEFGH', FFI::string($result, 8));
        $this->assertSame(1, $mock->readCount);
    }

    #[Test]
    public function testClearBufferForcesRemoteRead(): void
    {
        $mock = $this->createMockReader();
        $mock->preload(0x1000, 'ABCDEFGH');

        $buffered = new BufferedMemoryReader($mock);
        $buffered->prefetch(1, 0x1000, 8);
        $mock->readCount = 0;

        $buffered->clearBuffer();

        $result = $buffered->read(1, 0x1000, 8);
        $this->assertSame('ABCDEFGH', FFI::string($result, 8));
        $this->assertSame(1, $mock->readCount);
    }


    #[Test]
    public function testPrefetchRejectsOversizedRegion(): void
    {
        $mock = $this->createMockReader();
        $mock->preload(0x1000, str_repeat('A', 100));

        $buffered = new BufferedMemoryReader($mock, max_prefetch_size: 50);
        $buffered->prefetch(1, 0x1000, 100);

        // Buffer should be cleared, so this falls through
        $result = $buffered->read(1, 0x1000, 100);
        $this->assertSame(str_repeat('A', 100), FFI::string($result, 100));
    }

    #[Test]
    public function testSetMaxPrefetchSize(): void
    {
        $mock = $this->createMockReader();
        $buffered = new BufferedMemoryReader($mock, max_prefetch_size: 50);

        $this->assertSame(50, $buffered->getMaxPrefetchSize());

        $buffered->setMaxPrefetchSize(200);
        $this->assertSame(200, $buffered->getMaxPrefetchSize());
    }

    #[Test]
    public function testScatterGatherReadsOwnProcessMemory(): void
    {
        $mock = $this->createMockReader();
        $buffered = new BufferedMemoryReader($mock);

        $pid = getmypid();

        // Allocate two separate C buffers to scatter-gather read
        $ffi = FFI::cdef('');
        $region1 = $ffi->new('unsigned char[8]');
        $region2 = $ffi->new('unsigned char[8]');
        assert($region1 !== null && $region2 !== null);
        FFI::memcpy($region1, 'AAAABBBB', 8);
        FFI::memcpy($region2, 'CCCCDDDD', 8);

        $region1_ptr = FFI::addr($region1);
        $region2_ptr = FFI::addr($region2);
        $addr1 = FFIHelper::cast('long', $region1_ptr)->cdata;
        $addr2 = FFIHelper::cast('long', $region2_ptr)->cdata;

        // Scatter-gather: read both regions in one syscall
        $buffered->prefetchScatterGather($pid, [
            ['address' => $addr1, 'size' => 8],
            ['address' => $addr2, 'size' => 8],
        ]);

        // Read from region 1 buffer
        $result = $buffered->read($pid, $addr1, 4);
        $this->assertSame('AAAA', FFI::string($result, 4));

        // Read from region 1 buffer (offset)
        $result = $buffered->read($pid, $addr1 + 4, 4);
        $this->assertSame('BBBB', FFI::string($result, 4));

        // Read from region 2 buffer
        $result = $buffered->read($pid, $addr2, 8);
        $this->assertSame('CCCCDDDD', FFI::string($result, 8));

        // Inner reader should not have been called
        $this->assertSame(0, $mock->readCount);
    }

    #[Test]
    public function testPrefetchAdditionalAppendsWithoutClearingExistingSlots(): void
    {
        $mock = $this->createMockReader();
        $buffered = new BufferedMemoryReader($mock);

        $pid = getmypid();
        $ffi = FFI::cdef('');
        $region1 = $ffi->new('unsigned char[8]');
        $region2 = $ffi->new('unsigned char[8]');
        $region3 = $ffi->new('unsigned char[8]');
        assert($region1 !== null && $region2 !== null && $region3 !== null);
        FFI::memcpy($region1, 'AAAAAAAA', 8);
        FFI::memcpy($region2, 'BBBBBBBB', 8);
        FFI::memcpy($region3, 'CCCCCCCC', 8);

        $ptr1 = FFI::addr($region1);
        $ptr2 = FFI::addr($region2);
        $ptr3 = FFI::addr($region3);
        $addr1 = FFIHelper::cast('long', $ptr1)->cdata;
        $addr2 = FFIHelper::cast('long', $ptr2)->cdata;
        $addr3 = FFIHelper::cast('long', $ptr3)->cdata;

        $buffered->prefetchScatterGather($pid, [
            ['address' => $addr1, 'size' => 8],
            ['address' => $addr2, 'size' => 8],
        ]);

        $ok = $buffered->prefetchAdditional($pid, $addr3, 8);
        $this->assertTrue($ok);

        // All three regions must still be readable
        $this->assertSame('AAAAAAAA', FFI::string($buffered->read($pid, $addr1, 8), 8));
        $this->assertSame('BBBBBBBB', FFI::string($buffered->read($pid, $addr2, 8), 8));
        $this->assertSame('CCCCCCCC', FFI::string($buffered->read($pid, $addr3, 8), 8));

        $this->assertSame(0, $mock->readCount);
    }

    #[Test]
    public function testPrefetchAdditionalFailsWhenNoSlotIsFree(): void
    {
        $mock = $this->createMockReader();
        $buffered = new BufferedMemoryReader($mock);

        $pid = getmypid();
        $ffi = FFI::cdef('');
        $regions = [];
        $addrs = [];
        for ($i = 0; $i < BufferedMemoryReader::MAX_SCATTER_GATHER_REGIONS; $i++) {
            $regions[$i] = $ffi->new('unsigned char[8]');
            assert($regions[$i] !== null);
            FFI::memcpy($regions[$i], str_repeat(chr(ord('A') + $i), 8), 8);
            $ptr = FFI::addr($regions[$i]);
            $addrs[$i] = FFIHelper::cast('long', $ptr)->cdata;
        }
        $sg_regions = [];
        for ($i = 0; $i < BufferedMemoryReader::MAX_SCATTER_GATHER_REGIONS; $i++) {
            $sg_regions[] = ['address' => $addrs[$i], 'size' => 8];
        }
        $buffered->prefetchScatterGather($pid, $sg_regions);

        $extra = $ffi->new('unsigned char[8]');
        assert($extra !== null);
        $extra_ptr = FFI::addr($extra);
        $extra_addr = FFIHelper::cast('long', $extra_ptr)->cdata;

        $ok = $buffered->prefetchAdditional($pid, $extra_addr, 8);
        $this->assertFalse($ok);
    }

    #[Test]
    public function testScatterGatherClearsOldBuffers(): void
    {
        $mock = $this->createMockReader();
        $mock->preload(0x9000, 'OLD_DATA');

        $buffered = new BufferedMemoryReader($mock);
        $buffered->prefetch(1, 0x9000, 8);
        $mock->readCount = 0;

        // Scatter-gather should clear old buffers
        $pid = getmypid();
        $ffi = FFI::cdef('');
        $region = $ffi->new('unsigned char[4]');
        assert($region !== null);
        FFI::memcpy($region, 'NEW!', 4);
        $region_ptr = FFI::addr($region);
        $addr = FFIHelper::cast('long', $region_ptr)->cdata;

        $buffered->prefetchScatterGather($pid, [
            ['address' => $addr, 'size' => 4],
        ]);

        // Old buffer should be gone - falls through to inner
        $result = $buffered->read(1, 0x9000, 8);
        $this->assertSame(1, $mock->readCount);

        // New scatter-gather buffer should work
        $result = $buffered->read($pid, $addr, 4);
        $this->assertSame('NEW!', FFI::string($result, 4));
    }
}
