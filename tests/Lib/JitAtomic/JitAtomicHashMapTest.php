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

namespace Reli\Lib\JitAtomic;

use Reli\BaseTestCase;

class JitAtomicHashMapTest extends BaseTestCase
{
    protected function setUp(): void
    {
        if (!AtomicCodeRegion::isSupported()) {
            $this->markTestSkipped(
                'JitAtomic requires Linux x86_64 with FFI; '
                . 'arch=' . php_uname('m') . ', ffi=' . (extension_loaded('ffi') ? 'yes' : 'no'),
            );
        }
        try {
            AtomicCodeRegion::instance();
        } catch (\RuntimeException $e) {
            // mmap with PROT_EXEC may be denied (W^X containers, PaX, etc.)
            $this->markTestSkipped('AtomicCodeRegion init failed: ' . $e->getMessage());
        }
    }

    public function testInsertAndLookupBasic(): void
    {
        $map = new JitAtomicHashMap(64);

        $this->assertFalse($map->has(0xdead));
        $this->assertNull($map->get(0xdead));

        $prior = $map->setIfAbsent(0xdead, 42);
        $this->assertNull($prior, 'first insert should report no prior value');

        $this->assertTrue($map->has(0xdead));
        $this->assertSame(42, $map->get(0xdead));

        $prior = $map->setIfAbsent(0xdead, 999);
        $this->assertSame(42, $prior, 'second insert should report existing value, not overwrite');
        $this->assertSame(42, $map->get(0xdead), 'value should be unchanged');
    }

    public function testValueZeroIsPreserved(): void
    {
        // Value 0 must be distinguishable from "absent" — exercises the
        // high-bit occupancy flag in the machine code.
        $map = new JitAtomicHashMap(16);

        $this->assertFalse($map->has(0xbeef));
        $this->assertNull($map->setIfAbsent(0xbeef, 0));
        $this->assertTrue($map->has(0xbeef));
        $this->assertSame(0, $map->get(0xbeef));

        // A second insert should report the existing value (which is 0).
        $prior = $map->setIfAbsent(0xbeef, 7);
        $this->assertSame(0, $prior, 'existing value 0 must be returned, not null');
    }

    public function testManyKeysWithCollisions(): void
    {
        // Capacity 1024, 256 keys -> load factor 0.25, collisions guaranteed
        // because the keys are pseudo-random.
        $map = new JitAtomicHashMap(1024);

        mt_srand(1234);
        $expected = [];
        for ($i = 0; $i < 256; $i++) {
            $k = mt_rand(1, PHP_INT_MAX >> 8);
            $v = $i;
            $expected[$k] = $v;
            $map->setIfAbsent($k, $v);
        }

        foreach ($expected as $k => $v) {
            $this->assertTrue($map->has($k), "key $k expected to be present");
            $this->assertSame($v, $map->get($k), "value mismatch for key $k");
        }

        // Lookups for absent keys should return null.
        for ($i = 0; $i < 16; $i++) {
            $k = (PHP_INT_MAX >> 8) + $i + 1; // outside the inserted range
            if (!isset($expected[$k])) {
                $this->assertFalse($map->has($k));
                $this->assertNull($map->get($k));
            }
        }
    }

    public function testMatchesPhpArrayBehavior(): void
    {
        // Random workload, both PHP array<int,int> and JIT map should
        // agree on every observable answer.
        $map = new JitAtomicHashMap(4096);
        /** @var array<int,int> $ref */
        $ref = [];

        mt_srand(7);
        for ($i = 0; $i < 1500; $i++) {
            $k = mt_rand(1, PHP_INT_MAX >> 8);
            $v = mt_rand(0, 1_000_000);

            // Pre-state agreement
            $this->assertSame(isset($ref[$k]), $map->has($k), "has() disagree at op $i");
            $this->assertSame($ref[$k] ?? null, $map->get($k), "get() disagree at op $i");

            // setIfAbsent semantics
            $prior_ref = $ref[$k] ?? null;
            $prior_map = $map->setIfAbsent($k, $v);
            $this->assertSame($prior_ref, $prior_map, "setIfAbsent return disagree at op $i");
            if ($prior_ref === null) {
                $ref[$k] = $v;
            }

            // Post-state agreement
            $this->assertTrue($map->has($k));
            $this->assertSame($ref[$k], $map->get($k));
        }
    }

    public function testCapacityMustBePowerOfTwo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new JitAtomicHashMap(100);
    }

    public function testCapacityMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new JitAtomicHashMap(0);
    }
}
