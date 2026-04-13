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

namespace Reli\Inspector\Output\MemoryOutput\BinaryFormat;

use PHPUnit\Framework\TestCase;

class DiskBackedStringDictTest extends TestCase
{
    private ?DiskBackedStringDict $dict = null;

    protected function tearDown(): void
    {
        $this->dict?->cleanup();
    }

    public function testInternAndLookup(): void
    {
        $this->dict = new DiskBackedStringDict();
        $id0 = $this->dict->intern('hello');
        $id1 = $this->dict->intern('world');
        $id2 = $this->dict->intern('hello'); // dedup

        $this->assertSame(0, $id0);
        $this->assertSame(1, $id1);
        $this->assertSame(0, $id2);
        $this->assertSame('hello', $this->dict->lookup(0));
        $this->assertSame('world', $this->dict->lookup(1));
        $this->assertSame(2, $this->dict->count());
    }

    public function testNullReturnsNullStringId(): void
    {
        $this->dict = new DiskBackedStringDict();
        $id = $this->dict->intern(null);
        $this->assertSame(Format::NULL_STRING_ID, $id);
        $this->assertNull($this->dict->lookup(Format::NULL_STRING_ID));
    }

    public function testPrecomputedHash(): void
    {
        $this->dict = new DiskBackedStringDict();
        $id0 = $this->dict->intern('foo', 0xABCD1234);
        $id1 = $this->dict->intern('foo', 0xABCD1234); // same hash → dedup
        $this->assertSame($id0, $id1);
        $this->assertSame('foo', $this->dict->lookup($id0));
    }

    public function testPrecomputedHashZeroFallsThroughToCrc(): void
    {
        $this->dict = new DiskBackedStringDict();
        $id0 = $this->dict->intern('bar', 0); // 0 → compute own hash
        $id1 = $this->dict->intern('bar', 0); // same → dedup via crc
        $this->assertSame($id0, $id1);
    }

    public function testLruCacheEviction(): void
    {
        // Cache budget: 20 bytes. Each string is 10 bytes → cache fits 2.
        $this->dict = new DiskBackedStringDict(20);
        $this->dict->intern(str_repeat('a', 10)); // id 0, cached
        $this->dict->intern(str_repeat('b', 10)); // id 1, cached; total 20 bytes
        $this->dict->intern(str_repeat('c', 10)); // id 2, evicts 'a', total still 20

        // All should still be lookupable (disk fallback)
        $this->assertSame(str_repeat('a', 10), $this->dict->lookup(0));
        $this->assertSame(str_repeat('b', 10), $this->dict->lookup(1));
        $this->assertSame(str_repeat('c', 10), $this->dict->lookup(2));
    }

    public function testSerializeRoundTrip(): void
    {
        $this->dict = new DiskBackedStringDict();
        $this->dict->intern('alpha');
        $this->dict->intern('beta');
        $this->dict->intern('gamma');

        // Serialize via streaming to a temp file, then read back
        $tmpPath = tempnam(sys_get_temp_dir(), 'dict_ser_');
        $fh = fopen($tmpPath, 'w+b');
        $bytesWritten = $this->dict->serializeToStream($fh);
        $this->assertGreaterThan(0, $bytesWritten);

        // Read back and deserialize with StringDict (format-compatible)
        fseek($fh, 0);
        $data = fread($fh, $bytesWritten);
        fclose($fh);
        @unlink($tmpPath);

        $loaded = StringDict::deserialize($data);
        $this->assertSame('alpha', $loaded->lookup(0));
        $this->assertSame('beta', $loaded->lookup(1));
        $this->assertSame('gamma', $loaded->lookup(2));
        $this->assertSame(3, $loaded->count());
    }

    public function testLargeStringNotCachedIfExceedsBudget(): void
    {
        // Cache budget: 10 bytes. String is 100 bytes → won't be cached.
        $this->dict = new DiskBackedStringDict(10);
        $big = str_repeat('x', 100);
        $id = $this->dict->intern($big);
        // Still lookupable via disk
        $this->assertSame($big, $this->dict->lookup($id));
    }

    public function testDeduplicatesByContent(): void
    {
        $this->dict = new DiskBackedStringDict();
        // Two different strings with same crc32 would be a collision,
        // but different content should get different ids
        $id0 = $this->dict->intern('abc');
        $id1 = $this->dict->intern('def');
        $this->assertNotSame($id0, $id1);

        // Same content gets same id
        $id2 = $this->dict->intern('abc');
        $this->assertSame($id0, $id2);
    }

    public function testEmptyString(): void
    {
        $this->dict = new DiskBackedStringDict();
        $id = $this->dict->intern('');
        $this->assertSame(0, $id);
        $this->assertSame('', $this->dict->lookup(0));

        // Dedup for empty string
        $id2 = $this->dict->intern('');
        $this->assertSame(0, $id2);
    }

    public function testManyStringsMemoryBounded(): void
    {
        // Intern 10000 unique strings with a small cache.
        // Verify they all round-trip correctly.
        $this->dict = new DiskBackedStringDict(1024); // 1KB cache
        $ids = [];
        for ($i = 0; $i < 10000; $i++) {
            $ids[$i] = $this->dict->intern("string_{$i}");
        }
        $this->assertSame(10000, $this->dict->count());

        // Spot-check some lookups (will hit disk for most)
        $this->assertSame('string_0', $this->dict->lookup($ids[0]));
        $this->assertSame('string_5000', $this->dict->lookup($ids[5000]));
        $this->assertSame('string_9999', $this->dict->lookup($ids[9999]));
    }

    public function testSerializeRoundTripWithSparseOffsetBeyondTwoGiB(): void
    {
        $this->dict = new DiskBackedStringDict(1024);

        $highOffset = (1 << 31) + 4096;
        $this->seekBackingFile($highOffset);

        $id = $this->dict->intern('late_string');
        $this->assertSame(0, $id);

        $tmpPath = tempnam(sys_get_temp_dir(), 'dict_sparse_');
        $fh = fopen($tmpPath, 'w+b');
        $bytesWritten = $this->dict->serializeToStream($fh);
        $this->assertGreaterThan(0, $bytesWritten);

        fseek($fh, 0);
        $data = fread($fh, $bytesWritten);
        fclose($fh);
        @unlink($tmpPath);

        $loaded = StringDict::deserialize($data);
        $this->assertSame('late_string', $loaded->lookup(0));
    }

    private function seekBackingFile(int $offset): void
    {
        $diskPos = new \ReflectionProperty(DiskBackedStringDict::class, 'diskPos');
        $diskPos->setAccessible(true);
        $diskPos->setValue($this->dict, $offset);

        $diskFh = new \ReflectionProperty(DiskBackedStringDict::class, 'diskFh');
        $diskFh->setAccessible(true);
        $fh = $diskFh->getValue($this->dict);
        fseek($fh, $offset);
    }
}
