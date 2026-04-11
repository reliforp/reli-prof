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
 * Stateless aggregations over a {@see TraceModel}.
 *
 * Each method returns the same shape:
 *   [
 *     'counts' => array<int, int>  // key_id => count
 *     'matched_samples' => int
 *   ]
 *
 * `key_id` is a frame_id from `TraceModel::keysFor($no_line)`. The TUI
 * uses these maps to render tables and to map a selected row back to a
 * focusable frame.
 */
final class Aggregator
{
    public const VIEW_SELF = 'self';
    public const VIEW_TOTAL = 'total';
    public const VIEW_CALLERS = 'callers';
    public const VIEW_CALLEES = 'callees';

    /**
     * @return array{counts: array<int, int>, matched_samples: int}
     */
    public static function selfTime(TraceModel $model, ViewOptions $opts): array
    {
        $counts = [];
        $matched = 0;
        foreach ($model->samples as $stack) {
            if ($stack === []) {
                continue;
            }
            if (!self::sampleMatches($model, $stack, $opts)) {
                continue;
            }
            $matched++;
            $leaf_id = self::projectId($model, $stack[0], $opts->no_line);
            $counts[$leaf_id] = ($counts[$leaf_id] ?? 0) + 1;
        }
        return ['counts' => $counts, 'matched_samples' => $matched];
    }

    /**
     * @return array{counts: array<int, int>, matched_samples: int}
     */
    public static function totalTime(TraceModel $model, ViewOptions $opts): array
    {
        $counts = [];
        $matched = 0;
        foreach ($model->samples as $stack) {
            if ($stack === []) {
                continue;
            }
            if (!self::sampleMatches($model, $stack, $opts)) {
                continue;
            }
            $matched++;
            $seen = [];
            foreach ($stack as $fid) {
                $kid = self::projectId($model, $fid, $opts->no_line);
                if (isset($seen[$kid])) {
                    continue;
                }
                $seen[$kid] = true;
                $counts[$kid] = ($counts[$kid] ?? 0) + 1;
            }
        }
        return ['counts' => $counts, 'matched_samples' => $matched];
    }

    /**
     * Aggregate the immediate caller (one step toward the root) of the
     * focus frame for each sample whose stack contains it.
     *
     * @return array{counts: array<int, int>, matched_samples: int}
     */
    public static function callersOf(TraceModel $model, int $focus_id, ViewOptions $opts): array
    {
        $counts = [];
        $matched = 0;
        // <root> is represented as -1.
        foreach ($model->samples as $stack) {
            if ($stack === []) {
                continue;
            }
            if (!self::sampleMatches($model, $stack, $opts)) {
                continue;
            }
            $n = count($stack);
            for ($i = 0; $i < $n; $i++) {
                if (self::projectId($model, $stack[$i], $opts->no_line) !== $focus_id) {
                    continue;
                }
                $matched++;
                if ($i + 1 < $n) {
                    $caller = self::projectId($model, $stack[$i + 1], $opts->no_line);
                } else {
                    $caller = -1;
                }
                $counts[$caller] = ($counts[$caller] ?? 0) + 1;
                break;
            }
        }
        return ['counts' => $counts, 'matched_samples' => $matched];
    }

    /**
     * Aggregate the immediate callee (one step toward the leaf) of the
     * focus frame.
     *
     * @return array{counts: array<int, int>, matched_samples: int}
     */
    public static function calleesOf(TraceModel $model, int $focus_id, ViewOptions $opts): array
    {
        $counts = [];
        $matched = 0;
        // <leaf> is represented as -2.
        foreach ($model->samples as $stack) {
            if ($stack === []) {
                continue;
            }
            if (!self::sampleMatches($model, $stack, $opts)) {
                continue;
            }
            $n = count($stack);
            for ($i = $n - 1; $i >= 0; $i--) {
                if (self::projectId($model, $stack[$i], $opts->no_line) !== $focus_id) {
                    continue;
                }
                $matched++;
                $prev = $i - 1;
                if ($prev >= 0) {
                    $callee = self::projectId($model, $stack[$prev], $opts->no_line);
                } else {
                    $callee = -2;
                }
                $counts[$callee] = ($counts[$callee] ?? 0) + 1;
                break;
            }
        }
        return ['counts' => $counts, 'matched_samples' => $matched];
    }

    /**
     * Look up the display label for a key id, including the synthetic
     * `<root>` (-1) and `<leaf>` (-2) markers used by callers/callees.
     */
    public static function labelFor(TraceModel $model, int $key_id, bool $no_line): string
    {
        if ($key_id === -1) {
            return '<root>';
        }
        if ($key_id === -2) {
            return '<leaf>';
        }
        $keys = $model->keysFor($no_line);
        return $keys[$key_id] ?? "??:{$key_id}";
    }

    private static function projectId(TraceModel $model, int $frame_id, bool $no_line): int
    {
        return $no_line ? $model->no_line_map[$frame_id] : $frame_id;
    }

    /**
     * @param list<int> $stack
     */
    private static function sampleMatches(TraceModel $model, array $stack, ViewOptions $opts): bool
    {
        if ($opts->match_re === null) {
            return true;
        }
        $keys = $model->keysFor($opts->no_line);
        $seen = [];
        foreach ($stack as $frame_id) {
            $kid = self::projectId($model, $frame_id, $opts->no_line);
            if (isset($seen[$kid])) {
                continue;
            }
            $seen[$kid] = true;
            if (preg_match($opts->match_re, $keys[$kid] ?? '') === 1) {
                return true;
            }
        }
        return false;
    }
}
