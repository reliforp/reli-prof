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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation;

use PHPUnit\Framework\TestCase;

class FfiIntSetTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('FFI')) {
            $this->markTestSkipped('FFI required');
        }
    }

    public function testAddAndContains(): void
    {
        $set = new FfiIntSet(16);
        $set->add(42);

        $this->assertTrue($set->has(42));
    }

    public function testContainsMiss(): void
    {
        $set = new FfiIntSet(16);
        $set->add(42);

        $this->assertFalse($set->has(999));
    }

    public function testDuplicateAdd(): void
    {
        $set = new FfiIntSet(16);
        $set->add(42);
        $set->add(42);

        $this->assertSame(1, $set->count());
        $this->assertTrue($set->has(42));
    }

    public function testMultipleValues(): void
    {
        $set = new FfiIntSet(16);
        $values = [10, 20, 30, 40, 50, 60, 70, 80, 90, 100];

        foreach ($values as $v) {
            $set->add($v);
        }

        foreach ($values as $v) {
            $this->assertTrue($set->has($v), "Set should contain {$v}");
        }

        $this->assertFalse($set->has(999));
    }

    public function testCount(): void
    {
        $set = new FfiIntSet(16);
        $this->assertSame(0, $set->count());

        $set->add(1);
        $this->assertSame(1, $set->count());

        $set->add(2);
        $this->assertSame(2, $set->count());

        $set->add(3);
        $this->assertSame(3, $set->count());

        // Duplicate should not increase count
        $set->add(2);
        $this->assertSame(3, $set->count());
    }

    public function testGrowth(): void
    {
        // Start with capacity 8, grows at 75% = 6 entries
        $set = new FfiIntSet(8);

        $values = [];
        for ($i = 1; $i <= 20; $i++) {
            $values[] = $i * 1000;
            $set->add($i * 1000);
        }

        $this->assertSame(20, $set->count());

        // Verify all values are still present after growth
        foreach ($values as $v) {
            $this->assertTrue($set->has($v), "Value {$v} should be present after growth");
        }
    }
}
