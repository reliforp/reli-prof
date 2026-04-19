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

class FfiHashTableTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('FFI')) {
            $this->markTestSkipped('FFI required');
        }
    }

    public function testInsertAndFind(): void
    {
        $table = new FfiHashTable(16);
        $table->insert(42, 1, 100, 10);

        $results = $table->findByHash(42);
        $this->assertCount(1, $results);
        $this->assertSame([1, 100, 10], $results[0]);
    }

    public function testMultipleEntries(): void
    {
        $table = new FfiHashTable(16);
        $table->insert(10, 0, 0, 5);
        $table->insert(20, 1, 100, 15);
        $table->insert(30, 2, 200, 25);

        $this->assertSame([[0, 0, 5]], $table->findByHash(10));
        $this->assertSame([[1, 100, 15]], $table->findByHash(20));
        $this->assertSame([[2, 200, 25]], $table->findByHash(30));
    }

    public function testHashCollision(): void
    {
        $table = new FfiHashTable(16);
        // Insert multiple entries with the same hash
        $table->insert(99, 0, 0, 10);
        $table->insert(99, 1, 100, 20);
        $table->insert(99, 2, 200, 30);

        $results = $table->findByHash(99);
        $this->assertCount(3, $results);
        $this->assertSame([0, 0, 10], $results[0]);
        $this->assertSame([1, 100, 20], $results[1]);
        $this->assertSame([2, 200, 30], $results[2]);
    }

    public function testFindMiss(): void
    {
        $table = new FfiHashTable(16);
        $table->insert(42, 1, 100, 10);

        $results = $table->findByHash(999);
        $this->assertSame([], $results);
    }

    public function testCount(): void
    {
        $table = new FfiHashTable(16);
        $this->assertSame(0, $table->count());

        $table->insert(1, 0, 0, 1);
        $this->assertSame(1, $table->count());

        $table->insert(2, 1, 10, 2);
        $this->assertSame(2, $table->count());

        $table->insert(3, 2, 20, 3);
        $this->assertSame(3, $table->count());
    }

    public function testGrowth(): void
    {
        // Start with capacity 8, grows at 75% = 6 entries
        $table = new FfiHashTable(8);

        $entries = [];
        for ($i = 1; $i <= 20; $i++) {
            $hash = $i * 7; // spread hashes
            $table->insert($hash, $i, $i * 100, $i * 10);
            $entries[] = [$hash, $i, $i * 100, $i * 10];
        }

        $this->assertSame(20, $table->count());

        // Verify all entries are still findable after growth
        foreach ($entries as [$hash, $id, $offset, $len]) {
            $results = $table->findByHash($hash);
            $this->assertCount(1, $results, "Entry with hash {$hash} should be findable after growth");
            $this->assertSame([$id, $offset, $len], $results[0]);
        }
    }

    public function testLargeHash(): void
    {
        $table = new FfiHashTable(16);

        // Test with large positive hash
        $largeHash = PHP_INT_MAX;
        $table->insert($largeHash, 0, 500, 50);

        $results = $table->findByHash($largeHash);
        $this->assertCount(1, $results);
        $this->assertSame([0, 500, 50], $results[0]);

        // Test with large negative hash
        $negHash = PHP_INT_MIN + 1; // avoid PHP_INT_MIN which may have edge issues
        $table->insert($negHash, 1, 600, 60);

        $results = $table->findByHash($negHash);
        $this->assertCount(1, $results);
        $this->assertSame([1, 600, 60], $results[0]);
    }
}
