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

namespace Reli\Rbt\Explore;

use Reli\BaseTestCase;

/**
 * Targets the pure-function static helpers buried inside ExploreTui:
 *
 *   - text/width helpers (shorten / shortenLeft / padOrShorten /
 *     compactFlameLabel)
 *   - bar-rendering helpers (barChar / brailleHBar)
 *   - the flame layout pass (layoutFlameTree / layoutFlameNodes)
 *   - the maxRowCount aggregation helper
 *
 * They're all `private static`, so we reach in with reflection rather
 * than relaxing visibility — the visibility is part of the design and
 * the helpers exist for the TUI's own rendering, not as a public API.
 */
class ExploreTuiStaticHelpersTest extends BaseTestCase
{
    /**
     * @param array<int, mixed> $args
     */
    private static function call(string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod(ExploreTui::class, $method);
        return $ref->invokeArgs(null, $args);
    }

    // ---------- shorten ----------

    public function testShortenReturnsEmptyForNonPositiveWidth(): void
    {
        $this->assertSame('', self::call('shorten', ['hello', 0]));
        $this->assertSame('', self::call('shorten', ['hello', -3]));
    }

    public function testShortenLeavesShortStringsUntouched(): void
    {
        $this->assertSame('hi', self::call('shorten', ['hi', 5]));
        $this->assertSame('exact', self::call('shorten', ['exact', 5]));
    }

    public function testShortenAddsEllipsisOnTruncation(): void
    {
        $this->assertSame('hell…', self::call('shorten', ['hello world', 5]));
    }

    public function testShortenWithWidthOneTakesSingleChar(): void
    {
        // width <= 1 path: no room for the ellipsis, just hard-cut.
        $this->assertSame('h', self::call('shorten', ['hello', 1]));
    }

    // ---------- shortenLeft ----------

    public function testShortenLeftKeepsRightSide(): void
    {
        $this->assertSame('…orld', self::call('shortenLeft', ['hello world', 5]));
    }

    public function testShortenLeftLeavesShortStringsUntouched(): void
    {
        $this->assertSame('hi', self::call('shortenLeft', ['hi', 5]));
    }

    public function testShortenLeftWithWidthOneTakesLastChar(): void
    {
        $this->assertSame('o', self::call('shortenLeft', ['hello', 1]));
    }

    public function testShortenLeftReturnsEmptyForNonPositiveWidth(): void
    {
        $this->assertSame('', self::call('shortenLeft', ['hello', 0]));
    }

    // ---------- padOrShorten ----------

    public function testPadOrShortenPadsShortStrings(): void
    {
        $this->assertSame('hi   ', self::call('padOrShorten', ['hi', 5]));
    }

    public function testPadOrShortenTruncatesLongStringsWithEllipsis(): void
    {
        $this->assertSame('hell…', self::call('padOrShorten', ['hello world', 5]));
    }

    public function testPadOrShortenLeavesExactWidthUntouched(): void
    {
        $this->assertSame('exact', self::call('padOrShorten', ['exact', 5]));
    }

    // ---------- compactFlameLabel ----------

    public function testCompactFlameLabelKeepsFunctionAndLineDroppingFilePath(): void
    {
        $this->assertSame(
            'Foo::bar:42',
            self::call('compactFlameLabel', ['Foo::bar /var/www/src/foo.php:42']),
        );
    }

    public function testCompactFlameLabelReturnsRawLabelWhenNoSpace(): void
    {
        // No space → bare function name (no_line view) — pass through.
        $this->assertSame('bareFunction', self::call('compactFlameLabel', ['bareFunction']));
    }

    public function testCompactFlameLabelDropsTrailingPartWhenNoColonAfterSpace(): void
    {
        // Has a space but the colon (if any) is *before* the space →
        // the helper drops everything from the space onward, since
        // the trailing chunk isn't a recognisable file:line tail.
        $this->assertSame(
            'Foo::bar',
            self::call('compactFlameLabel', ['Foo::bar weirdsuffix']),
        );
    }

    // ---------- barChar ----------

    public function testBarCharReturnsSpaceForZeroOrNegative(): void
    {
        $this->assertSame(' ', self::call('barChar', [0.0]));
        $this->assertSame(' ', self::call('barChar', [-0.5]));
    }

    public function testBarCharReturnsLowestStepForTinyRatio(): void
    {
        $this->assertSame('▁', self::call('barChar', [0.05]));
    }

    public function testBarCharReturnsFullBlockAtOne(): void
    {
        $this->assertSame('█', self::call('barChar', [1.0]));
        $this->assertSame('█', self::call('barChar', [0.9]));
    }

    public function testBarCharStepsThroughIntermediateBlocks(): void
    {
        $this->assertSame('▄', self::call('barChar', [0.45]));
        $this->assertSame('▆', self::call('barChar', [0.7]));
    }

    // ---------- brailleHBar ----------

    public function testBrailleHBarFullRatioFillsAllCells(): void
    {
        $bar = self::call('brailleHBar', [1.0, 4]);
        $this->assertIsString($bar);
        $this->assertSame(str_repeat('⣿', 4), $bar);
    }

    public function testBrailleHBarZeroRatioRendersBlanks(): void
    {
        $this->assertSame(str_repeat(' ', 3), self::call('brailleHBar', [0.0, 3]));
    }

    public function testBrailleHBarClampsRatioBelowZero(): void
    {
        $this->assertSame(str_repeat(' ', 3), self::call('brailleHBar', [-0.5, 3]));
    }

    public function testBrailleHBarClampsRatioAboveOne(): void
    {
        $this->assertSame(str_repeat('⣿', 3), self::call('brailleHBar', [1.5, 3]));
    }

    public function testBrailleHBarHalfFillRendersBothFullAndPartial(): void
    {
        // 4 cells × 2 sub-pixels = 8 sub-pixels; 0.5 → 4 sub-pixels.
        // First two cells fully filled, last two empty.
        $bar = self::call('brailleHBar', [0.5, 4]);
        $this->assertSame('⣿⣿  ', $bar);
    }

    // ---------- maxRowCount ----------

    public function testMaxRowCountReturnsMaxOfFirstColumn(): void
    {
        $rows = [
            [10, 1, 'a'],
            [42, 2, 'b'],
            [7, 3, 'c'],
        ];
        $this->assertSame(42, self::call('maxRowCount', [$rows]));
    }

    public function testMaxRowCountReturnsZeroForEmpty(): void
    {
        $this->assertSame(0, self::call('maxRowCount', [[]]));
    }

    // ---------- layoutFlameTree ----------

    public function testLayoutFlameTreeAllocatesWidthProportionally(): void
    {
        $tree = [
            10 => [
                'count' => 8,
                'children' => [
                    20 => ['count' => 4, 'children' => []],
                    21 => ['count' => 4, 'children' => []],
                ],
            ],
            11 => [
                'count' => 2,
                'children' => [],
            ],
        ];
        // denom 10, total width 100, max depth 5
        $rows = self::call('layoutFlameTree', [$tree, 10, 100, 5]);
        $this->assertIsArray($rows);

        // Depth 1 has the two top-level nodes. Largest first.
        $this->assertArrayHasKey(1, $rows);
        $this->assertCount(2, $rows[1]);
        $this->assertSame(10, $rows[1][0]['key_id']);
        $this->assertSame(80, $rows[1][0]['w']);
        $this->assertSame(0, $rows[1][0]['x']);
        $this->assertSame(11, $rows[1][1]['key_id']);
        $this->assertSame(20, $rows[1][1]['w']);
        $this->assertSame(80, $rows[1][1]['x']);

        // Depth 2 contains the children of node 10, sized relative to
        // its 80-cell footprint: 4/8 each → 40 cells each.
        $this->assertArrayHasKey(2, $rows);
        $this->assertCount(2, $rows[2]);
        $this->assertSame(40, $rows[2][0]['w']);
        $this->assertSame(40, $rows[2][1]['w']);
        $this->assertSame(0, $rows[2][0]['x']);
        $this->assertSame(40, $rows[2][1]['x']);
    }

    public function testLayoutFlameTreeReturnsEmptyForZeroDenominator(): void
    {
        $tree = [10 => ['count' => 5, 'children' => []]];
        $rows = self::call('layoutFlameTree', [$tree, 0, 100, 5]);
        $this->assertSame([], $rows);
    }

    public function testLayoutFlameTreeStopsAtMaxDepth(): void
    {
        $tree = [
            1 => [
                'count' => 10,
                'children' => [
                    2 => [
                        'count' => 10,
                        'children' => [
                            3 => ['count' => 10, 'children' => []],
                        ],
                    ],
                ],
            ],
        ];
        // max_depth = 1 → only depth 1 should appear.
        $rows = self::call('layoutFlameTree', [$tree, 10, 100, 1]);
        $this->assertIsArray($rows);
        $this->assertArrayHasKey(1, $rows);
        $this->assertArrayNotHasKey(2, $rows);
    }

    public function testLayoutFlameTreeDropsZeroWidthChildren(): void
    {
        // child width = floor(1/100 * 10) = 0 → dropped
        $tree = [
            1 => ['count' => 99, 'children' => []],
            2 => ['count' => 1, 'children' => []],
        ];
        $rows = self::call('layoutFlameTree', [$tree, 100, 10, 5]);
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows[1]);
        $this->assertSame(1, $rows[1][0]['key_id']);
        $this->assertSame(9, $rows[1][0]['w']);
    }
}
