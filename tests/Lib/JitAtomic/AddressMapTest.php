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

/**
 * Verifies that PhpArrayAddressMap and JitAtomicAddressMap behave
 * identically for the single-thread MemoryLocationsCollector access
 * pattern (the only caller today). The shared workload is run against
 * both implementations and a reference plain `array<int, int>`.
 */
class AddressMapTest extends BaseTestCase
{
    public function testFactoryReturnsSomethingUsable(): void
    {
        $map = AddressMapFactory::create();
        $this->assertInstanceOf(AddressMap::class, $map);

        // Whichever implementation we got, it must satisfy the contract
        // for at least one round-trip.
        $map->set(0xdead, 7);
        $this->assertTrue($map->has(0xdead));
        $this->assertSame(7, $map->get(0xdead));
        $this->assertFalse($map->has(0xbeef));
        $this->assertNull($map->get(0xbeef));
    }

    public function testPhpArrayBasic(): void
    {
        $this->runBasicWorkload(new PhpArrayAddressMap());
    }

    public function testJitAtomicBasic(): void
    {
        if (!AtomicCodeRegion::isSupported()) {
            $this->markTestSkipped('JitAtomic not supported on this platform');
        }
        try {
            $map = new JitAtomicAddressMap(1024);
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('JitAtomic init failed: ' . $e->getMessage());
        }
        $this->runBasicWorkload($map);
    }

    public function testBothImplementationsAgreeOnRandomWorkload(): void
    {
        if (!AtomicCodeRegion::isSupported()) {
            $this->markTestSkipped('JitAtomic not supported on this platform');
        }
        try {
            $jit = new JitAtomicAddressMap(4096);
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('JitAtomic init failed: ' . $e->getMessage());
        }
        $php = new PhpArrayAddressMap();
        /** @var array<int, int> $ref */
        $ref = [];

        mt_srand(13);
        for ($i = 0; $i < 1000; $i++) {
            $k = mt_rand(1, 1 << 30);
            $v = mt_rand(0, 1 << 20);

            // Pre-state agreement
            $expected_has = isset($ref[$k]);
            $expected_get = $ref[$k] ?? null;
            $this->assertSame($expected_has, $php->has($k));
            $this->assertSame($expected_get, $php->get($k));
            $this->assertSame($expected_has, $jit->has($k));
            $this->assertSame($expected_get, $jit->get($k));

            // Mutate (single-threaded "first writer wins" since neither
            // impl re-records different node_ids in real reli usage).
            if (!$expected_has) {
                $ref[$k] = $v;
                $php->set($k, $v);
                $jit->set($k, $v);
            }

            // Post-state agreement
            $this->assertSame($ref[$k], $php->get($k));
            $this->assertSame($ref[$k], $jit->get($k));
        }
    }

    private function runBasicWorkload(AddressMap $map): void
    {
        $this->assertFalse($map->has(0x1000));
        $this->assertNull($map->get(0x1000));

        $map->set(0x1000, 42);
        $this->assertTrue($map->has(0x1000));
        $this->assertSame(42, $map->get(0x1000));

        // value 0 must round-trip even though some implementations
        // need extra care to distinguish "absent" from "stored 0".
        $map->set(0x2000, 0);
        $this->assertTrue($map->has(0x2000));
        $this->assertSame(0, $map->get(0x2000));

        $this->assertFalse($map->has(0x9999));
    }
}
