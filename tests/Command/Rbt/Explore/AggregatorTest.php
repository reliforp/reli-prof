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

namespace Reli\Command\Rbt\Explore;

use Reli\BaseTestCase;

class AggregatorTest extends BaseTestCase
{
    /**
     * Build a synthetic model with three line-aware frames:
     *   0: leaf_a /a.php:1
     *   1: leaf_b /a.php:2  (same function as 0 in no-line space)
     *   2: mid    /m.php:1
     *   3: root   /r.php:1
     *
     * No-line space:
     *   0: leaf_a (groups frames 0 and 1)
     *   1: mid
     *   2: root
     *
     * Samples (leaf-to-root):
     *   [0, 2, 3]   leaf_a:1 -> mid -> root
     *   [0, 2, 3]   (duplicate)
     *   [1, 2, 3]   leaf_a:2 -> mid -> root
     *   [2, 3]      mid -> root  (no leaf frame)
     */
    private function buildModel(): TraceModel
    {
        return new TraceModel(
            frame_keys: [
                'leaf_a /a.php:1',
                'leaf_a /a.php:2',
                'mid /m.php:1',
                'root /r.php:1',
            ],
            frame_keys_no_line: [
                'leaf_a',
                'mid',
                'root',
            ],
            no_line_map: [0, 0, 1, 2],
            samples: [
                [0, 2, 3],
                [0, 2, 3],
                [1, 2, 3],
                [2, 3],
            ],
            sampling_period_us: 10000,
            source_path: '/tmp/synth.rbt',
        );
    }

    public function testSelfTimeLineAware(): void
    {
        $model = $this->buildModel();
        $r = Aggregator::selfTime($model, new ViewOptions());
        $this->assertSame(4, $r['matched_samples']);
        $this->assertSame(
            [
                0 => 2, // leaf_a /a.php:1
                1 => 1, // leaf_a /a.php:2
                2 => 1, // mid /m.php:1 (leaf in last sample)
            ],
            $r['counts'],
        );
    }

    public function testSelfTimeNoLineGroupsByName(): void
    {
        $model = $this->buildModel();
        $r = Aggregator::selfTime($model, new ViewOptions(no_line: true));
        // leaf_a is leaf in 3 samples (frames 0,0,1), mid is leaf in 1 sample.
        $this->assertSame(
            [
                0 => 3, // leaf_a (no-line id 0)
                1 => 1, // mid (no-line id 1)
            ],
            $r['counts'],
        );
    }

    public function testTotalTimeDedupesPerSample(): void
    {
        $model = $this->buildModel();
        $r = Aggregator::totalTime($model, new ViewOptions());
        $this->assertSame(4, $r['matched_samples']);
        $this->assertSame(
            [
                0 => 2, // leaf_a:1 in samples 0,1
                2 => 4, // mid in all samples
                3 => 4, // root in all samples
                1 => 1, // leaf_a:2 in sample 2
            ],
            $r['counts'],
        );
    }

    public function testCallersOfMidIsRoot(): void
    {
        $model = $this->buildModel();
        // Focus mid (frame_id=2). The frame closer to root in every sample is root (3).
        $r = Aggregator::callersOf($model, 2, new ViewOptions());
        $this->assertSame(4, $r['matched_samples']);
        $this->assertSame([3 => 4], $r['counts']);
    }

    public function testCallersOfRootIsSyntheticRoot(): void
    {
        $model = $this->buildModel();
        // root has no caller — synthetic <root> = -1, all 4 samples.
        $r = Aggregator::callersOf($model, 3, new ViewOptions());
        $this->assertSame(4, $r['matched_samples']);
        $this->assertSame([-1 => 4], $r['counts']);
    }

    public function testCalleesOfMid(): void
    {
        $model = $this->buildModel();
        // mid's callee = leaf in line-space: 0 in samples 0,1 ; 1 in sample 2 ;
        // sample 3 has no leaf -> synthetic <leaf> = -2.
        $r = Aggregator::calleesOf($model, 2, new ViewOptions());
        $this->assertSame(4, $r['matched_samples']);
        $this->assertSame(
            [
                0 => 2,
                1 => 1,
                -2 => 1,
            ],
            $r['counts'],
        );
    }

    public function testCalleesOfMidNoLine(): void
    {
        $model = $this->buildModel();
        // mid (no-line id 1) — callees in no-line space: leaf_a (0) ×3, <leaf> ×1.
        $r = Aggregator::calleesOf($model, 1, new ViewOptions(no_line: true));
        $this->assertSame(4, $r['matched_samples']);
        $this->assertSame(
            [
                0 => 3,
                -2 => 1,
            ],
            $r['counts'],
        );
    }

    public function testGlobalMatchFiltersWholeSamples(): void
    {
        $model = $this->buildModel();
        // Only samples whose stack contains "leaf_a" → first three samples.
        $r = Aggregator::selfTime(
            $model,
            new ViewOptions(match_re: '#leaf_a#'),
        );
        $this->assertSame(3, $r['matched_samples']);
        $this->assertArrayNotHasKey(2, $r['counts']); // mid-as-leaf sample dropped
    }

    public function testLabelForSyntheticIds(): void
    {
        $model = $this->buildModel();
        $this->assertSame('<root>', Aggregator::labelFor($model, -1, false));
        $this->assertSame('<leaf>', Aggregator::labelFor($model, -2, false));
        $this->assertSame('mid /m.php:1', Aggregator::labelFor($model, 2, false));
        $this->assertSame('mid', Aggregator::labelFor($model, 1, true));
    }
}
