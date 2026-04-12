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

class SandwichBuilderTest extends BaseTestCase
{
    /**
     * Two samples land on the same focus frame at the same depth.
     * The caller-side and callee-side trees should both have a single
     * branch with count 2 at every level.
     */
    public function testBuildsCallerAndCalleeTreesAcrossSamples(): void
    {
        // frame_keys layout: leaf → root order in each sample.
        // We'll focus on id 2 ("focus").
        $model = $this->makeModel(
            frame_keys: ['leaf', 'middle', 'focus', 'caller', 'root'],
            samples: [
                [0, 1, 2, 3, 4],
                [0, 1, 2, 3, 4],
            ],
        );

        $result = SandwichBuilder::build($model, focus_id: 2, opts: new ViewOptions());

        $this->assertSame(2, $result['focus_count']);

        // callers walks j = pos+1 .. n-1 = 3, 4 → caller (3), then root (4).
        $this->assertArrayHasKey(3, $result['callers']);
        $this->assertSame(2, $result['callers'][3]['count']);
        $this->assertArrayHasKey(4, $result['callers'][3]['children']);
        $this->assertSame(2, $result['callers'][3]['children'][4]['count']);
        $this->assertSame([], $result['callers'][3]['children'][4]['children']);

        // callees walks j = pos-1 .. 0 = 1, 0 → middle (1), then leaf (0).
        $this->assertArrayHasKey(1, $result['callees']);
        $this->assertSame(2, $result['callees'][1]['count']);
        $this->assertArrayHasKey(0, $result['callees'][1]['children']);
        $this->assertSame(2, $result['callees'][1]['children'][0]['count']);
    }

    /**
     * Branching: two samples share a caller but diverge in their leaves.
     * The caller subtree should aggregate (count=2) while the callee
     * subtrees should split into two separate keys.
     */
    public function testBranchesCalleeTreeWhenLeavesDiffer(): void
    {
        $model = $this->makeModel(
            frame_keys: ['leafA', 'leafB', 'focus', 'caller'],
            samples: [
                [0, 2, 3], // leafA, focus, caller
                [1, 2, 3], // leafB, focus, caller
            ],
        );

        $result = SandwichBuilder::build($model, focus_id: 2, opts: new ViewOptions());

        $this->assertSame(2, $result['focus_count']);
        $this->assertSame(2, $result['callers'][3]['count']);
        // Two distinct leaf branches, each seen once.
        $this->assertSame(1, $result['callees'][0]['count']);
        $this->assertSame(1, $result['callees'][1]['count']);
    }

    /**
     * When focus appears more than once in a single stack the builder
     * picks the *leafward-most* occurrence (the first hit in the
     * leaf→root scan). The caller-side count therefore reflects the
     * deeper position, not both positions.
     */
    public function testUsesLeafwardMostFocusOccurrence(): void
    {
        $model = $this->makeModel(
            frame_keys: ['focus', 'mid', 'focus', 'top'],
            samples: [
                // Stack contains focus at indices 0 and 2 (note: both
                // indices use frame id 0, since "focus" is the same key).
                [0, 1, 0, 3],
            ],
        );

        $result = SandwichBuilder::build($model, focus_id: 0, opts: new ViewOptions());

        $this->assertSame(1, $result['focus_count']);
        // Leafward-most focus is at index 0, so callers = j=1..3 → mid, focus, top.
        $this->assertArrayHasKey(1, $result['callers']);
        $this->assertSame(1, $result['callers'][1]['count']);
        // No callees: pos = 0, j range pos-1..0 is empty.
        $this->assertSame([], $result['callees']);
    }

    /**
     * Match filter drops samples whose stack contains no matching frame.
     * Sample 1 contains "focus" + "wanted" → kept. Sample 2 contains
     * "focus" only → dropped before contributing to any tree.
     */
    public function testMatchFilterExcludesNonMatchingSamples(): void
    {
        $model = $this->makeModel(
            frame_keys: ['focus', 'wanted', 'other'],
            samples: [
                [0, 1], // focus, wanted
                [0, 2], // focus, other
            ],
        );

        $opts = new ViewOptions(no_line: false, match_re: '#^wanted$#');
        $result = SandwichBuilder::build($model, focus_id: 0, opts: $opts);

        $this->assertSame(1, $result['focus_count']);
        // Only the wanted-bearing sample contributes to callers.
        $this->assertArrayHasKey(1, $result['callers']);
        $this->assertArrayNotHasKey(2, $result['callers']);
    }

    /**
     * no_line=true projects frame ids through no_line_map. Two samples
     * with the same function at different lines should share the focus
     * id under the no-line view.
     */
    public function testNoLineModeProjectsFramesThroughNoLineMap(): void
    {
        // Two with-line frames "handler" map to no-line id 0.
        // Caller frame "main" maps to no-line id 1.
        $model = new TraceModel(
            frame_keys: ['handler /h.php:1', 'handler /h.php:2', 'main /m.php:1'],
            frame_keys_no_line: ['handler', 'main'],
            no_line_map: [0, 0, 1],
            samples: [
                [0, 2], // handler:1 → main
                [1, 2], // handler:2 → main
            ],
            sampling_period_us: 10000,
            source_path: '/dev/null',
        );

        $result = SandwichBuilder::build(
            $model,
            focus_id: 0, // no-line "handler"
            opts: new ViewOptions(no_line: true),
        );

        $this->assertSame(2, $result['focus_count']);
        // Caller "main" (no-line id 1) seen twice.
        $this->assertSame(2, $result['callers'][1]['count']);
    }

    /**
     * Focus that doesn't appear in any sample → empty trees and zero
     * focus_count. Empty stacks are also skipped silently.
     */
    public function testReturnsEmptyTreesWhenFocusAbsent(): void
    {
        $model = $this->makeModel(
            frame_keys: ['a', 'b'],
            samples: [
                [0, 1],
                [],
            ],
        );

        $result = SandwichBuilder::build($model, focus_id: 999, opts: new ViewOptions());

        $this->assertSame(0, $result['focus_count']);
        $this->assertSame([], $result['callers']);
        $this->assertSame([], $result['callees']);
    }

    /**
     * @param list<string> $frame_keys
     * @param list<list<int>> $samples
     */
    private function makeModel(array $frame_keys, array $samples): TraceModel
    {
        // No-line view tests construct their own model so we can keep
        // this helper trivial: identity no-line projection (each frame
        // is its own no-line group).
        $no_line_map = [];
        foreach (array_keys($frame_keys) as $i) {
            $no_line_map[] = $i;
        }
        return new TraceModel(
            frame_keys: $frame_keys,
            frame_keys_no_line: $frame_keys,
            no_line_map: $no_line_map,
            samples: $samples,
            sampling_period_us: 10000,
            source_path: '/dev/null',
        );
    }
}
