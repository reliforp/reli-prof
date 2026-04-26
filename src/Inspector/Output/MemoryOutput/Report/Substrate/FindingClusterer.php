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

namespace Reli\Inspector\Output\MemoryOutput\Report\Substrate;

use Reli\Inspector\Output\MemoryOutput\Report\Finding;

/**
 * Group findings that all describe the same underlying phenomenon — the
 * same class, the same node — so the text formatter can render one
 * representative per cluster instead of N copies of "this class is big".
 *
 * Real-world worst case from the corpus: rw3_messenger-envelopes
 * surfaces 22 findings for a single 50k-envelope accumulation, split
 * across `choke_point`, `bottleneck_path`, `companion_cluster`,
 * `expensive_property` (×6), `empty_object` (×3), `ownership_pattern`,
 * `structural_duplicate` (×7), `dedup_candidate`. Without clustering
 * the section eats half a screen with the same target repeated under
 * different detector tags.
 *
 * Two grouping keys are tried in order:
 *   1. A class-shaped fact (`class_name`, `owned_class`, `target_class`).
 *      `class_name` is what most kinds publish; `owned_class` and
 *      `target_class` are aliases used by `ownership_pattern` and
 *      `dedup_candidate` for the same semantic — the class the finding
 *      is *about*.
 *   2. `evidence_node_ids[0]`. When no class fact is available, the
 *      first evidence node uniquely identifies the target.
 * Findings carrying neither are ungroupable and render as singletons.
 *
 * Within each cluster the highest-severity finding is the
 * representative. Caller is expected to have already sorted findings
 * by severity, so iterating in input order naturally lands the
 * highest-severity entry as the cluster head.
 */
final class FindingClusterer
{
    /** @var list<string> */
    private const CLASS_FIELDS = ['class_name', 'owned_class', 'target_class'];

    /**
     * Group input findings by target. Iteration order is preserved:
     * the first finding of each cluster determines that cluster's
     * position in the output, so a severity-sorted input produces a
     * severity-sorted output.
     *
     * @param list<Finding> $findings
     * @return list<array{representative: Finding, others: list<Finding>}>
     */
    public static function cluster(array $findings): array
    {
        /** @var array<string, array{representative: Finding, others: list<Finding>}> $groups */
        $groups = [];
        /** @var list<array{representative: Finding, others: list<Finding>}> $singletons */
        $singletons = [];
        /** @var list<string|int> $insertion_order */
        $insertion_order = [];

        $singleton_seq = 0;
        foreach ($findings as $finding) {
            $key = self::targetKey($finding);
            if ($key === null) {
                $singletons[] = ['representative' => $finding, 'others' => []];
                $insertion_order[] = $singleton_seq;
                $singleton_seq++;
                continue;
            }
            if (!isset($groups[$key])) {
                $groups[$key] = ['representative' => $finding, 'others' => []];
                $insertion_order[] = $key;
                continue;
            }
            $groups[$key]['others'][] = $finding;
        }

        // Walk insertion order to interleave class/node groups with
        // ungroupable singletons in the order they first appeared. A
        // pure `array_values($groups)` would lose the relative position
        // of the singletons, scrambling severity-sorted input.
        $out = [];
        $singleton_idx = 0;
        foreach ($insertion_order as $token) {
            if (is_int($token)) {
                $out[] = $singletons[$singleton_idx];
                $singleton_idx++;
            } else {
                $out[] = $groups[$token];
            }
        }
        return $out;
    }

    /**
     * Build a one-line summary of the suppressed members of a cluster:
     * `expensive_property×6, empty_object×3, structural_duplicate×7`.
     * Kinds appear in first-seen order so the line reads as a
     * chronological "and we also saw …" rather than a re-sorted index.
     *
     * @param list<Finding> $others
     */
    public static function summariseOtherKinds(array $others): string
    {
        /** @var array<string, int> $counts */
        $counts = [];
        /** @var list<string> $order */
        $order = [];
        foreach ($others as $finding) {
            if (!isset($counts[$finding->kind])) {
                $counts[$finding->kind] = 0;
                $order[] = $finding->kind;
            }
            $counts[$finding->kind]++;
        }
        $parts = [];
        foreach ($order as $kind) {
            $n = $counts[$kind];
            $parts[] = $n > 1 ? "{$kind}×{$n}" : $kind;
        }
        return implode(', ', $parts);
    }

    /**
     * @psalm-suppress MixedAssignment
     */
    private static function targetKey(Finding $finding): ?string
    {
        foreach (self::CLASS_FIELDS as $field) {
            $value = $finding->facts[$field] ?? null;
            if (is_string($value) && $value !== '') {
                return "class:{$value}";
            }
        }
        if ($finding->evidence_node_ids !== []) {
            return "node:{$finding->evidence_node_ids[0]}";
        }
        return null;
    }
}
