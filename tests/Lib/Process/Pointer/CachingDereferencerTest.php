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

namespace Reli\Lib\Process\Pointer;

use Reli\BaseTestCase;

class CachingDereferencerTest extends BaseTestCase
{
    public function testCacheHitReturnsSameObject(): void
    {
        $result = new \stdClass();
        $result->value = 42;

        $inner = \Mockery::mock(Dereferencer::class);
        $inner->expects('deref')->once()->andReturn($result);

        $caching = new CachingDereferencer($inner);

        /** @var Pointer<Dereferencable> $pointer */
        $pointer = new Pointer('TestType', 0x1000, 64);

        $first = $caching->deref($pointer);
        $second = $caching->deref($pointer);

        $this->assertSame($first, $second);
        $this->assertSame(42, $first->value);
    }

    public function testDifferentAddressesCallInner(): void
    {
        $result1 = new \stdClass();
        $result1->id = 1;
        $result2 = new \stdClass();
        $result2->id = 2;

        $inner = \Mockery::mock(Dereferencer::class);
        $inner->expects('deref')->twice()->andReturnUsing(function (Pointer $p) use ($result1, $result2) {
            return $p->address === 0x1000 ? $result1 : $result2;
        });

        $caching = new CachingDereferencer($inner);

        /** @var Pointer<Dereferencable> $p1 */
        $p1 = new Pointer('TestType', 0x1000, 64);
        /** @var Pointer<Dereferencable> $p2 */
        $p2 = new Pointer('TestType', 0x2000, 64);

        $this->assertSame(1, $caching->deref($p1)->id);
        $this->assertSame(2, $caching->deref($p2)->id);
    }

    public function testEvictsOldEntriesWhenFull(): void
    {
        $inner = \Mockery::mock(Dereferencer::class);
        $call_count = 0;
        $inner->allows('deref')->andReturnUsing(function () use (&$call_count) {
            return new \stdClass();
        });

        $caching = new CachingDereferencer($inner, max_entries: 4);

        for ($i = 0; $i < 6; $i++) {
            /** @var Pointer<Dereferencable> $p */
            $p = new Pointer('Type', $i * 0x1000, 64);
            $caching->deref($p);
        }

        // After eviction, early entries should miss cache
        /** @var Pointer<Dereferencable> $p0 */
        $p0 = new Pointer('Type', 0x0000, 64);
        $a = $caching->deref($p0);
        $b = $caching->deref($p0);
        // $a was re-fetched (cache miss after eviction), $b is cached
        $this->assertSame($a, $b);
    }

    public function testClearCacheRemovesAllEntries(): void
    {
        $inner = \Mockery::mock(Dereferencer::class);
        $inner->allows('deref')->andReturnUsing(fn () => new \stdClass());

        $caching = new CachingDereferencer($inner);

        /** @var Pointer<Dereferencable> $p */
        $p = new Pointer('Type', 0x1000, 64);

        $first = $caching->deref($p);
        $caching->clearCache();
        $second = $caching->deref($p);

        $this->assertNotSame($first, $second);
    }

    public function testDifferentTypeSameAddressAreSeparateEntries(): void
    {
        $inner = \Mockery::mock(Dereferencer::class);
        $inner->allows('deref')->andReturnUsing(fn () => new \stdClass());

        $caching = new CachingDereferencer($inner);

        /** @var Pointer<Dereferencable> $p1 */
        $p1 = new Pointer('TypeA', 0x1000, 64);
        /** @var Pointer<Dereferencable> $p2 */
        $p2 = new Pointer('TypeB', 0x1000, 64);

        $a = $caching->deref($p1);
        $b = $caching->deref($p2);

        $this->assertNotSame($a, $b);
    }
}
