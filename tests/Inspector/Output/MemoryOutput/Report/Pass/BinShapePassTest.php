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

namespace Reli\Inspector\Output\MemoryOutput\Report\Pass;

use Reli\BaseTestCase;
use Reli\Inspector\Output\MemoryOutput\Report\Finding;

class BinShapePassTest extends BaseTestCase
{
    public function testEmitsNothingWithoutPayload(): void
    {
        $pass = new BinShapePass([['memory_get_usage' => 1]]);
        $this->assertSame([], $pass->analyze());
    }

    public function testEmitsBinShapeCountFinding(): void
    {
        $pass = new BinShapePass([[
            'bin_shape_counts' => json_encode([
                'bin' => [
                    [
                        'bin_num' => 3,
                        'shapes' => [
                            [
                                'label' => 'Bucket(zval IS_STRING)',
                                'count' => 2150,
                                'reachable_count' => 150,
                                'confidence' => 'medium',
                            ],
                        ],
                        'unclassified' => 601,
                    ],
                ],
            ]),
        ]]);
        $findings = $pass->analyze();
        // One bin_shape_count + one bin_shape_unclassified.
        $this->assertCount(2, $findings);
        $kinds = array_map(static fn(Finding $f) => $f->kind, $findings);
        $this->assertSame(['bin_shape_count', 'bin_shape_unclassified'], $kinds);

        $shape_finding = $findings[0];
        $this->assertSame(2150, $shape_finding->facts['count']);
        $this->assertSame(2000, $shape_finding->facts['orphan_count']);
        $this->assertSame(150, $shape_finding->facts['reachable_count']);
        // 2150 / (2150 + 601) ≈ 78.2%
        $this->assertEqualsWithDelta(78.2, $shape_finding->facts['percentage'], 0.5);
    }

    public function testToleratesArrayInput(): void
    {
        $pass = new BinShapePass([[
            'bin_shape_counts' => [
                'bin' => [
                    [
                        'bin_num' => 3,
                        'shapes' => [
                            [
                                'label' => 'X',
                                'count' => 10,
                                'reachable_count' => 0,
                                'confidence' => 'high',
                            ],
                        ],
                        'unclassified' => 0,
                    ],
                ],
            ],
        ]]);
        $findings = $pass->analyze();
        $this->assertCount(1, $findings);
    }
}
