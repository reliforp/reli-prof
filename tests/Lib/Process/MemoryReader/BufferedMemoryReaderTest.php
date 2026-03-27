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
}

/**
 * @internal test helper
 */
class MockMemoryReader implements MemoryReaderInterface
{
    /** @var array<string, string> */
    public array $data = [];
    public int $readCount = 0;

    public function preload(int $address, string $bytes): void
    {
        $this->data[$address . ':' . strlen($bytes)] = $bytes;
    }

    #[\Override]
    public function read(int $pid, int $remote_address, int $size): FFI\CData
    {
        $this->readCount++;
        $key = $remote_address . ':' . $size;
        $bytes = $this->data[$key] ?? str_repeat("\0", $size);
        $buf = FFI::new("char[$size]");
        assert($buf !== null);
        FFI::memcpy($buf, $bytes, $size);
        /** @var \FFI\CArray<int> */
        return $buf;
    }
}
