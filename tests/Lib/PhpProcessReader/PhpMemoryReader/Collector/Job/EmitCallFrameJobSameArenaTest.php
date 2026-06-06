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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\Job;

use PHPUnit\Framework\TestCase;

/**
 * Pins the arena gating used by
 * {@see EmitCallFrameJob::collectCallFrameHeader()} when sizing a
 * frame from its successor's address. The gating must reject any
 * successor that lands outside the frame's own arena — a 4096 B
 * window alone would not catch the case where two arenas happen to
 * sit adjacently in address space.
 */
class EmitCallFrameJobSameArenaTest extends TestCase
{
    /**
     * Build a lookup table of (arena_begin, arena_end, node_id)
     * triples, sorted by begin.
     *
     * @param list<array{int, int}> $arenas pairs of [begin, size]
     * @return list<array{int, int, int}>
     */
    private function lookup(array $arenas): array
    {
        $rows = [];
        $id = 1;
        foreach ($arenas as [$begin, $size]) {
            $rows[] = [$begin, $begin + $size, $id++];
        }
        usort($rows, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        return $rows;
    }

    public function testEmptyLookupNeverMatches(): void
    {
        $this->assertFalse(
            EmitCallFrameJob::sameArena([], 0x1000, 0x1100),
        );
    }

    public function testBothInSameArenaIsTrue(): void
    {
        $lookup = $this->lookup([
            [0x1000, 0x200],
            [0x2000, 0x200],
        ]);

        $this->assertTrue(
            EmitCallFrameJob::sameArena($lookup, 0x1050, 0x1100),
        );
        $this->assertTrue(
            EmitCallFrameJob::sameArena($lookup, 0x2010, 0x2150),
        );
    }

    public function testCrossArenaIsFalseEvenWhenAdjacent(): void
    {
        // Two arenas sitting immediately next to each other: the
        // successor sits 16 bytes (= one zval) into the next arena.
        // The 4096 B window alone would accept this; the same-arena
        // gate must reject it.
        $lookup = $this->lookup([
            [0x1000, 0x100],
            [0x1100, 0x100],
        ]);

        $this->assertFalse(
            EmitCallFrameJob::sameArena($lookup, 0x10f0, 0x1110),
        );
    }

    public function testAddressBelowAllArenasIsFalse(): void
    {
        $lookup = $this->lookup([
            [0x2000, 0x100],
        ]);

        $this->assertFalse(
            EmitCallFrameJob::sameArena($lookup, 0x1000, 0x1100),
        );
    }

    public function testAddressAboveAllArenasIsFalse(): void
    {
        $lookup = $this->lookup([
            [0x1000, 0x100],
        ]);

        $this->assertFalse(
            EmitCallFrameJob::sameArena($lookup, 0x9000, 0x9100),
        );
    }

    public function testFrameInsideButSuccessorInsideADifferentArenaIsFalse(): void
    {
        $lookup = $this->lookup([
            [0x1000, 0x100],
            [0x5000, 0x100],
        ]);

        // Frame sits inside the first arena; successor sits inside
        // the second. Same-arena gate must reject this even though
        // both addresses are inside *some* tracked arena.
        $this->assertFalse(
            EmitCallFrameJob::sameArena($lookup, 0x1050, 0x5050),
        );
    }

    public function testSuccessorAtExclusiveEndBoundaryIsFalse(): void
    {
        // The end address is exclusive ([begin, end)).
        $lookup = $this->lookup([
            [0x1000, 0x100],
        ]);

        $this->assertFalse(
            EmitCallFrameJob::sameArena($lookup, 0x1080, 0x1100),
        );
    }

    public function testFrameAtBeginAndSuccessorJustInsideIsTrue(): void
    {
        $lookup = $this->lookup([
            [0x1000, 0x100],
        ]);

        $this->assertTrue(
            EmitCallFrameJob::sameArena($lookup, 0x1000, 0x10ff),
        );
    }
}
