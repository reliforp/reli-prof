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

/**
 * Builds a speedscope-style sandwich tree for a focus frame from the
 * model's full sample stacks.
 *
 * For each sample whose stack contains the focus frame, picks the
 * leafward-most occurrence as the canonical position, walks toward the
 * root to grow the caller tree, and walks toward the leaf to grow the
 * callee tree. Each tree is keyed by projected frame id; depth 1 is
 * the immediate caller / callee of the focus, depth 2 is the next
 * step out, and so on.
 *
 * Complexity is O(samples * stack_depth). On large captures (1M+
 * samples) this is the dominant cost of the popup view, so the result
 * is meant to be cached per (focus_id, no_line, match_re) tuple by the
 * caller (see ExploreTui::ensureSandwichTree).
 *
 * @psalm-type SandwichForest = array<int, array{count: int, children: array<array-key, mixed>}>
 */
final class SandwichBuilder
{
    /**
     * @return array{focus_count: int, callers: SandwichForest, callees: SandwichForest}
     *
     * The two `for ($j = ...)` loops below grow nested trees by walking
     * an array reference (`$cur = &$cur[$kid]['children']`). Psalm can't
     * track the recursive shape through `&` alias re-binding, so it
     * collapses `$cur` to mixed after the first re-bind and reports
     * Mixed* on every descendant access. The algorithm is correct, the
     * inputs are typed, and the alternative (path-rebuilding from the
     * root on every step) would be O(stack_depth^2) per sample on a
     * loop that already dominates the popup-view cost. Suppress the
     * Mixed* / MixedReturnTypeCoercion family on this function only.
     *
     * @psalm-suppress MixedArrayAccess, MixedArrayAssignment, MixedAssignment, MixedOperand, MixedReturnTypeCoercion
     */
    public static function build(
        TraceModel $model,
        int $focus_id,
        ViewOptions $opts,
    ): array {
        /** @var SandwichForest $callers */
        $callers = [];
        /** @var SandwichForest $callees */
        $callees = [];
        $matched = 0;

        $no_line = $opts->no_line;
        $with_opcode = $opts->with_opcode;
        $match_re = $opts->match_re;
        $keys = $model->keysFor($no_line, $with_opcode);

        foreach ($model->samples as $stack) {
            $n = count($stack);
            if ($n === 0) {
                continue;
            }

            // Optional global match filter (same semantics as Aggregator).
            if ($match_re !== null) {
                $hit = false;
                $seen = [];
                foreach ($stack as $fid) {
                    $kid = self::project($model, $fid, $no_line, $with_opcode);
                    if (isset($seen[$kid])) {
                        continue;
                    }
                    $seen[$kid] = true;
                    if (preg_match($match_re, $keys[$kid] ?? '') === 1) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) {
                    continue;
                }
            }

            // Locate the leafward-most occurrence of the focus frame.
            $pos = -1;
            for ($i = 0; $i < $n; $i++) {
                $kid = self::project($model, $stack[$i], $no_line, $with_opcode);
                if ($kid === $focus_id) {
                    $pos = $i;
                    break;
                }
            }
            if ($pos === -1) {
                continue;
            }

            $matched++;

            // Walk toward the root: callers tree.
            $cur = &$callers;
            for ($j = $pos + 1; $j < $n; $j++) {
                $kid = self::project($model, $stack[$j], $no_line, $with_opcode);
                if (!isset($cur[$kid])) {
                    $cur[$kid] = ['count' => 0, 'children' => []];
                }
                $cur[$kid]['count']++;
                $cur = &$cur[$kid]['children'];
            }
            unset($cur);

            // Walk toward the leaf: callees tree.
            $cur = &$callees;
            for ($j = $pos - 1; $j >= 0; $j--) {
                $kid = self::project($model, $stack[$j], $no_line, $with_opcode);
                if (!isset($cur[$kid])) {
                    $cur[$kid] = ['count' => 0, 'children' => []];
                }
                $cur[$kid]['count']++;
                $cur = &$cur[$kid]['children'];
            }
            unset($cur);
        }

        return [
            'focus_count' => $matched,
            'callers' => $callers,
            'callees' => $callees,
        ];
    }

    private static function project(TraceModel $model, int $frame_id, bool $no_line, bool $with_opcode): int
    {
        if ($with_opcode) {
            return $no_line
                ? $model->no_line_opcode_map[$frame_id]
                : $model->opcode_map[$frame_id];
        }
        return $no_line ? $model->no_line_map[$frame_id] : $frame_id;
    }
}
