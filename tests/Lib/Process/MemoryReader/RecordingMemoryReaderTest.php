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

class RecordingMemoryReaderTest extends TestCase
{
    private function createMockReader(): MemoryReaderInterface
    {
        return new class () implements MemoryReaderInterface {
            /** @var array<string, string> pre-loaded data keyed by "addr:size" */
            public array $data = [];

            public function preload(int $address, string $bytes): void
            {
                $this->data[$address . ':' . strlen($bytes)] = $bytes;
            }

            #[\Override]
            public function read(int $pid, int $remote_address, int $size): FFI\CData
            {
                $key = $remote_address . ':' . $size;
                $bytes = $this->data[$key] ?? str_repeat("\0", $size);
                $buf = FFIHelper::new("char[$size]");
                assert($buf !== null);
                FFI::memcpy($buf, $bytes, $size);
                /** @var \FFI\CArray<int> */
                return $buf;
            }
        };
    }

    #[Test]
    public function testRecordsReads(): void
    {
        $mock = $this->createMockReader();
        $mock->preload(0x1000, 'AAAA');
        $mock->preload(0x2000, 'BBBB');

        $recorder = new RecordingMemoryReader($mock);
        $recorder->read(1, 0x1000, 4);
        $recorder->read(1, 0x2000, 4);

        $regions = $recorder->getRecordedRegions();
        $this->assertCount(2, $regions);
        $this->assertSame(0x1000, $regions[0]['address']);
        $this->assertSame(4, $regions[0]['size']);
        $this->assertSame('AAAA', $regions[0]['data']);
        $this->assertSame(0x2000, $regions[1]['address']);
        $this->assertSame('BBBB', $regions[1]['data']);
    }

    #[Test]
    public function testMergesAdjacentRegions(): void
    {
        $mock = $this->createMockReader();
        $mock->preload(0x1000, 'AAAA');
        $mock->preload(0x1004, 'BBBB');

        $recorder = new RecordingMemoryReader($mock);
        $recorder->read(1, 0x1000, 4);
        $recorder->read(1, 0x1004, 4);

        $regions = $recorder->getRecordedRegions();
        $this->assertCount(1, $regions);
        $this->assertSame(0x1000, $regions[0]['address']);
        $this->assertSame(8, $regions[0]['size']);
        $this->assertSame('AAAABBBB', $regions[0]['data']);
    }

    #[Test]
    public function testMergesOverlappingRegions(): void
    {
        $mock = $this->createMockReader();
        $mock->preload(0x1000, 'AABBCC');
        $mock->preload(0x1002, 'XXYYZZ');

        $recorder = new RecordingMemoryReader($mock);
        $recorder->read(1, 0x1000, 6);
        $recorder->read(1, 0x1002, 6);

        $regions = $recorder->getRecordedRegions();
        $this->assertCount(1, $regions);
        $this->assertSame(0x1000, $regions[0]['address']);
        $this->assertSame(8, $regions[0]['size']);
        // First 6 bytes from first read, then 2 new bytes from second
        $this->assertSame("AABBCC" . "ZZ", $regions[0]['data']);
    }

    #[Test]
    public function testMergesNearbyRegionsWithinGap(): void
    {
        $mock = $this->createMockReader();
        $mock->preload(0x1000, 'AA');
        $mock->preload(0x1050, 'BB'); // 78 bytes gap, within default 128

        $recorder = new RecordingMemoryReader($mock);
        $recorder->read(1, 0x1000, 2);
        $recorder->read(1, 0x1050, 2);

        $regions = $recorder->getRecordedRegions();
        $this->assertCount(1, $regions);
        $this->assertSame(0x1000, $regions[0]['address']);
        $this->assertSame(0x52, $regions[0]['size']); // 0x1050 + 2 - 0x1000
    }

    #[Test]
    public function testDoesNotMergeFarRegions(): void
    {
        $mock = $this->createMockReader();
        $mock->preload(0x1000, 'AA');
        $mock->preload(0x2000, 'BB'); // 4094 bytes gap, beyond 128

        $recorder = new RecordingMemoryReader($mock);
        $recorder->read(1, 0x1000, 2);
        $recorder->read(1, 0x2000, 2);

        $regions = $recorder->getRecordedRegions();
        $this->assertCount(2, $regions);
    }

    #[Test]
    public function testEmptyReturnsEmpty(): void
    {
        $mock = $this->createMockReader();
        $recorder = new RecordingMemoryReader($mock);
        $this->assertSame([], $recorder->getRecordedRegions());
    }

    #[Test]
    public function testPassesThroughToInnerReader(): void
    {
        $mock = $this->createMockReader();
        $mock->preload(0x5000, 'HELLO');

        $recorder = new RecordingMemoryReader($mock);
        $result = $recorder->read(42, 0x5000, 5);

        $this->assertSame('HELLO', FFI::string($result, 5));
    }
}
