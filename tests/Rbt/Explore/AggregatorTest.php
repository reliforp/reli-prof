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

    public function testSelfTimeWithOpcodeDistinguishesSameFunctionByOpcode(): void
    {
        // Two frames for "handler" with different opcodes.
        $model = new TraceModel(
            frame_keys: ['handler /h.php:1', 'caller /c.php:1'],
            frame_keys_no_line: ['handler', 'caller'],
            no_line_map: [0, 1],
            samples: [
                [0, 1],
                [0, 1],
            ],
            sampling_period_us: 10000,
            source_path: '/dev/null',
            frame_keys_opcode: ['handler /h.php:1 [ASSIGN]', 'caller /c.php:1'],
            frame_keys_no_line_opcode: ['handler [ASSIGN]', 'caller'],
            opcode_map: [0, 1],
            no_line_opcode_map: [0, 1],
        );

        // Without opcode: both samples share frame_id 0.
        $r = Aggregator::selfTime($model, new ViewOptions(with_opcode: false));
        $this->assertSame([0 => 2], $r['counts']);

        // With opcode: counts are keyed by opcode_map[frame_id].
        $r = Aggregator::selfTime($model, new ViewOptions(with_opcode: true));
        $this->assertSame([0 => 2], $r['counts']); // opcode_map[0] = 0
    }

    public function testTotalTimeWithOpcodeAndNoLine(): void
    {
        $model = new TraceModel(
            frame_keys: ['foo /a.php:1', 'foo /a.php:2', 'bar /b.php:1'],
            frame_keys_no_line: ['foo', 'bar'],
            no_line_map: [0, 0, 1],
            samples: [
                [0, 2], // foo:1 -> bar
                [1, 2], // foo:2 -> bar
            ],
            sampling_period_us: 10000,
            source_path: '/dev/null',
            frame_keys_opcode: ['foo /a.php:1 [ASSIGN]', 'foo /a.php:2 [RETURN]', 'bar /b.php:1'],
            frame_keys_no_line_opcode: ['foo [ASSIGN]', 'foo [RETURN]', 'bar'],
            opcode_map: [0, 1, 2],
            no_line_opcode_map: [0, 1, 2],
        );

        // no_line + with_opcode: projects via no_line_opcode_map.
        $r = Aggregator::totalTime($model, new ViewOptions(no_line: true, with_opcode: true));
        // foo [ASSIGN] (nlo_id 0) in sample 0 only, foo [RETURN] (nlo_id 1) in sample 1 only.
        $this->assertSame(1, $r['counts'][0]); // foo [ASSIGN]
        $this->assertSame(1, $r['counts'][1]); // foo [RETURN]
        $this->assertSame(2, $r['counts'][2]); // bar in both
    }

    public function testLabelForWithOpcodeKeys(): void
    {
        $model = new TraceModel(
            frame_keys: ['foo /a.php:1'],
            frame_keys_no_line: ['foo'],
            no_line_map: [0],
            samples: [[0]],
            sampling_period_us: 10000,
            source_path: '/dev/null',
            frame_keys_opcode: ['foo /a.php:1 [ASSIGN]'],
            frame_keys_no_line_opcode: ['foo [ASSIGN]'],
            opcode_map: [0],
            no_line_opcode_map: [0],
        );

        $this->assertSame('foo /a.php:1 [ASSIGN]', Aggregator::labelFor($model, 0, false, true));
        $this->assertSame('foo [ASSIGN]', Aggregator::labelFor($model, 0, true, true));
        // Synthetic markers still work with opcode flag.
        $this->assertSame('<root>', Aggregator::labelFor($model, -1, false, true));
        $this->assertSame('<leaf>', Aggregator::labelFor($model, -2, true, true));
    }

    public function testLabelForSyntheticIds(): void
    {
        $model = $this->buildModel();
        $this->assertSame('<root>', Aggregator::labelFor($model, -1, false));
        $this->assertSame('<leaf>', Aggregator::labelFor($model, -2, false));
        $this->assertSame('mid /m.php:1', Aggregator::labelFor($model, 2, false));
        $this->assertSame('mid', Aggregator::labelFor($model, 1, true));
    }

    public function testTotalTimeReportsRootDistanceSum(): void
    {
        $model = $this->buildModel();
        $r = Aggregator::totalTime($model, new ViewOptions());
        // Per-sample positions (leaf→root indexing in stack arrays):
        //   [0,2,3] n=3 → leaf_a:1 dist=2, mid dist=1, root dist=0
        //   [0,2,3] same
        //   [1,2,3] n=3 → leaf_a:2 dist=2, mid dist=1, root dist=0
        //   [2,3]   n=2 → mid dist=1, root dist=0
        $this->assertSame(
            [
                0 => 4, // leaf_a:1 appears twice, each at dist 2
                2 => 4, // mid appears in 4 samples, each at dist 1
                3 => 0, // root appears in 4 samples, each at dist 0
                1 => 2, // leaf_a:2 appears once at dist 2
            ],
            $r['root_distance_sum'],
        );
    }

    public function testSelfTimeReportsRootDistanceSum(): void
    {
        $model = $this->buildModel();
        $r = Aggregator::selfTime($model, new ViewOptions());
        // Leaf-only credits: dist is n-1 for the sample.
        //   samples 0,1: leaf=0, n=3 → +2 each
        //   sample 2:    leaf=1, n=3 → +2
        //   sample 3:    leaf=2 (mid), n=2 → +1
        $this->assertSame(
            [
                0 => 4,
                1 => 2,
                2 => 1,
            ],
            $r['root_distance_sum'],
        );
    }

    public function testCallersOfReportsRootDistanceSum(): void
    {
        $model = $this->buildModel();
        // Focus mid. Real caller is root in all four samples; distance
        // from root is 0 in every case.
        $r = Aggregator::callersOf($model, 2, new ViewOptions());
        $this->assertSame([3 => 0], $r['root_distance_sum']);
    }

    public function testCallersOfSyntheticRootUsesNegativeOneDistance(): void
    {
        $model = $this->buildModel();
        // Focus root; it is always the bottom of the stack so the
        // synthetic <root> marker accumulates -1 per sample.
        $r = Aggregator::callersOf($model, 3, new ViewOptions());
        $this->assertSame([-1 => -4], $r['root_distance_sum']);
    }

    public function testCalleesOfReportsRootDistanceSum(): void
    {
        $model = $this->buildModel();
        // Focus mid. Callee dist from root:
        //   [0,2,3] callee=0 at i=0, n=3 → dist = n - i_focus = 3 - 1 = 2
        //   [0,2,3] same
        //   [1,2,3] callee=1, same → dist = 2
        //   [2,3]   no callee → synthetic <leaf> at virtual position,
        //                       dist = n = 2
        $r = Aggregator::calleesOf($model, 2, new ViewOptions());
        $this->assertSame(
            [
                0 => 4,
                1 => 2,
                -2 => 2,
            ],
            $r['root_distance_sum'],
        );
    }
}
