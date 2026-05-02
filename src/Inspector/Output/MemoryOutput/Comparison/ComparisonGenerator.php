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

namespace Reli\Inspector\Output\MemoryOutput\Comparison;

use PhpCast\Cast;
use Reli\Inspector\Output\MemoryOutput\Report\Finding;
use Reli\Inspector\Output\MemoryOutput\Report\FindingConfidence;
use Reli\Inspector\Output\MemoryOutput\Report\FindingSeverity;
use Reli\Inspector\Output\MemoryOutput\Report\ReportResult;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\SizeFormatter;
use Reli\Lib\PhpInternals\Types\Zend\ZendMmBinsInfo;

final class ComparisonGenerator
{
    private const SUMMARY_METRICS = [
        'memory_get_usage',
        'memory_get_real_usage',
        'memory_get_peak_usage',
        'memory_limit',
        'zend_mm_heap_total',
        'zend_mm_heap_usage',
        'vm_stack_total',
        'vm_stack_usage',
        'compiler_arena_total',
        'compiler_arena_usage',
        'heap_memory_analyzed_percentage',
        'rss',
    ];

    /** kinds that are ranking/informational — excluded from findings diff */
    private const RANKING_KINDS = [
        'overview',
        'coverage_gap',
        'call_stack',
        'type_ranking',
        'class_ranking',
        'retained_exact',
        'retained_approximate',
        'bin_histogram_overview',
        'bin_histogram_entry',
        'bin_periodic_group',
        'bin_periodic_orphan',
        'bin_periodic_reachable',
        'bin_shape_count',
        'bin_shape_unclassified',
    ];

    public function compare(
        ComparisonDataProvider $baseline,
        ComparisonDataProvider $target,
        float $threshold_percent = 0.0,
        bool $full_analysis = false,
        ?bool $ffi_csr = null,
    ): ComparisonResult {
        $baseline_report = $baseline->generateReport($full_analysis, $ffi_csr);
        $target_report = $target->generateReport($full_analysis, $ffi_csr);

        $summary_deltas = $this->compareSummaries(
            $baseline->loadSummaryMap(),
            $target->loadSummaryMap(),
            $threshold_percent,
        );

        $type_deltas = $this->compareTypes(
            $baseline->loadTypeMap(),
            $target->loadTypeMap(),
            $threshold_percent,
        );

        [$class_changes, $class_added, $class_removed] = $this->compareClasses(
            $baseline->loadClassMap(),
            $target->loadClassMap(),
            $threshold_percent,
        );

        $findings_diff = $this->compareFindings(
            $baseline_report,
            $target_report,
        );

        $bin_deltas = $this->compareBinHistogram(
            $baseline->loadBinHistogramSnapshot(),
            $target->loadBinHistogramSnapshot(),
        );

        $region_deltas = $this->compareRegionMap(
            $baseline->loadRegionMap(),
            $target->loadRegionMap(),
        );

        $bin_shape_deltas = $this->compareBinShapeCounts(
            $baseline->loadBinShapeCounts(),
            $target->loadBinShapeCounts(),
        );

        $unaccounted = $this->detectUnaccountedDelta(
            $summary_deltas,
            $type_deltas,
        );

        return new ComparisonResult(
            baseline_meta: $baseline_report->meta,
            target_meta: $target_report->meta,
            summary_deltas: $summary_deltas,
            type_deltas: $type_deltas,
            class_changes: $class_changes,
            class_added: $class_added,
            class_removed: $class_removed,
            findings_diff: $findings_diff,
            bin_deltas: $bin_deltas,
            unaccounted_finding: $unaccounted,
            region_deltas: $region_deltas,
            bin_shape_deltas: $bin_shape_deltas,
        );
    }

    /**
     * Outer-join two per-(bin, shape) snapshots and emit BinShapeDelta
     * rows for every shape whose count moved. Sorted by |orphan_delta|
     * descending — the leak signal is "orphans grew", regardless of
     * whether reachable copies of the shape also grew on the side.
     *
     * @param array<int, array<string, array{
     *     count: int,
     *     reachable_count: int,
     *     confidence: string,
     *     sample_addrs: list<int>
     * }>>|null $baseline
     * @param array<int, array<string, array{
     *     count: int,
     *     reachable_count: int,
     *     confidence: string,
     *     sample_addrs: list<int>
     * }>>|null $target
     * @return list<BinShapeDelta>
     */
    private function compareBinShapeCounts(?array $baseline, ?array $target): array
    {
        if ($baseline === null && $target === null) {
            return [];
        }
        $baseline ??= [];
        $target ??= [];

        $bin_nums = array_unique(array_merge(array_keys($baseline), array_keys($target)));

        $out = [];
        foreach ($bin_nums as $bin_num) {
            $b_bin = $baseline[$bin_num] ?? [];
            $t_bin = $target[$bin_num] ?? [];
            $labels = array_unique(array_merge(array_keys($b_bin), array_keys($t_bin)));
            foreach ($labels as $label) {
                $b = $b_bin[$label] ?? [
                    'count' => 0,
                    'reachable_count' => 0,
                    'confidence' => 'medium',
                    'sample_addrs' => [],
                ];
                $t = $t_bin[$label] ?? [
                    'count' => 0,
                    'reachable_count' => 0,
                    'confidence' => 'medium',
                    'sample_addrs' => [],
                ];
                $count_delta = $t['count'] - $b['count'];
                if ($count_delta === 0) {
                    continue;
                }
                $reachable_delta = $t['reachable_count'] - $b['reachable_count'];
                $orphan_delta = $count_delta - $reachable_delta;
                $out[] = new BinShapeDelta(
                    bin_num: $bin_num,
                    bin_size: \Reli\Lib\PhpInternals\Types\Zend\ZendMmBinsInfo::getSize($bin_num),
                    shape: $label,
                    baseline_count: $b['count'],
                    target_count: $t['count'],
                    count_delta: $count_delta,
                    baseline_reachable: $b['reachable_count'],
                    target_reachable: $t['reachable_count'],
                    reachable_count_delta: $reachable_delta,
                    orphan_count_delta: $orphan_delta,
                    confidence: $t['confidence'] !== '' ? $t['confidence'] : $b['confidence'],
                );
            }
        }

        usort(
            $out,
            static fn(BinShapeDelta $a, BinShapeDelta $b)
                => abs($b->orphan_count_delta) <=> abs($a->orphan_count_delta),
        );
        return $out;
    }

    /**
     * Diff two region-map snapshots into Added / Grown / Shrunk / Removed
     * rows. The snapshot is persisted by analyze (see
     * {@see \Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionMap}) so this
     * works on the rmem-only workflow without holding both rdumps.
     *
     * Identity key is `(kind, address)` rather than address alone — a
     * vm_stack and a chunk that happen to start at the same address are
     * still semantically distinct, and the chunk pool re-mmaps freed
     * chunks at fresh addresses anyway, so we don't need to track size
     * mutations across kinds.
     *
     * @param list<array{kind: string, address: int, size: int}>|null $baseline
     * @param list<array{kind: string, address: int, size: int}>|null $target
     * @return list<RegionDelta>
     */
    private function compareRegionMap(?array $baseline, ?array $target): array
    {
        if ($baseline === null && $target === null) {
            return [];
        }
        $baseline ??= [];
        $target ??= [];

        /** @var array<string, array{kind: string, address: int, size: int}> $b_index */
        $b_index = [];
        foreach ($baseline as $row) {
            $b_index[$row['kind'] . ':' . $row['address']] = $row;
        }
        /** @var array<string, array{kind: string, address: int, size: int}> $t_index */
        $t_index = [];
        foreach ($target as $row) {
            $t_index[$row['kind'] . ':' . $row['address']] = $row;
        }

        $deltas = [];
        foreach ($t_index as $k => $t_row) {
            $b_row = $b_index[$k] ?? null;
            if ($b_row === null) {
                $deltas[] = new RegionDelta(
                    kind: $t_row['kind'],
                    address: $t_row['address'],
                    baseline_size: 0,
                    target_size: $t_row['size'],
                    size_delta: $t_row['size'],
                    change: RegionDelta::CHANGE_ADDED,
                );
                continue;
            }
            $size_delta = $t_row['size'] - $b_row['size'];
            if ($size_delta > 0) {
                $deltas[] = new RegionDelta(
                    kind: $t_row['kind'],
                    address: $t_row['address'],
                    baseline_size: $b_row['size'],
                    target_size: $t_row['size'],
                    size_delta: $size_delta,
                    change: RegionDelta::CHANGE_GROWN,
                );
            } elseif ($size_delta < 0) {
                $deltas[] = new RegionDelta(
                    kind: $t_row['kind'],
                    address: $t_row['address'],
                    baseline_size: $b_row['size'],
                    target_size: $t_row['size'],
                    size_delta: $size_delta,
                    change: RegionDelta::CHANGE_SHRUNK,
                );
            }
            // size_delta === 0 → no row emitted (region unchanged).
        }
        foreach ($b_index as $k => $b_row) {
            if (isset($t_index[$k])) {
                continue;
            }
            $deltas[] = new RegionDelta(
                kind: $b_row['kind'],
                address: $b_row['address'],
                baseline_size: $b_row['size'],
                target_size: 0,
                size_delta: -$b_row['size'],
                change: RegionDelta::CHANGE_REMOVED,
            );
        }

        // Sort by absolute size delta — biggest movers first; stable
        // tie-break on address keeps output deterministic.
        usort(
            $deltas,
            static function (RegionDelta $a, RegionDelta $b): int {
                $cmp = abs($b->size_delta) <=> abs($a->size_delta);
                return $cmp !== 0 ? $cmp : ($a->address <=> $b->address);
            },
        );

        return $deltas;
    }

    /**
     * @param array{
     *     histogram: array<int, array{count: int, total_bytes: int}>,
     *     large_run_count?: int,
     *     large_run_bytes?: int,
     *     live_small_slot_count?: int,
     *     live_small_slot_bytes?: int,
     *     walked_chunk_count?: int,
     *     partial?: bool
     * }|null $baseline
     * @param array{
     *     histogram: array<int, array{count: int, total_bytes: int}>,
     *     large_run_count?: int,
     *     large_run_bytes?: int,
     *     live_small_slot_count?: int,
     *     live_small_slot_bytes?: int,
     *     walked_chunk_count?: int,
     *     partial?: bool
     * }|null $target
     * @return list<BinDelta>
     */
    private function compareBinHistogram(?array $baseline, ?array $target): array
    {
        if ($baseline === null && $target === null) {
            return [];
        }
        $b_hist = $baseline['histogram'] ?? [];
        $t_hist = $target['histogram'] ?? [];

        $bins = array_unique(array_merge(array_keys($b_hist), array_keys($t_hist)));
        $deltas = [];
        foreach ($bins as $bin_num) {
            $b = $b_hist[$bin_num] ?? ['count' => 0, 'total_bytes' => 0];
            $t = $t_hist[$bin_num] ?? ['count' => 0, 'total_bytes' => 0];
            $count_delta = $t['count'] - $b['count'];
            $bytes_delta = $t['total_bytes'] - $b['total_bytes'];
            if ($count_delta === 0 && $bytes_delta === 0) {
                continue;
            }
            $deltas[] = new BinDelta(
                bin_num: $bin_num,
                bin_size: ZendMmBinsInfo::getSize($bin_num),
                baseline_count: $b['count'],
                target_count: $t['count'],
                baseline_bytes: $b['total_bytes'],
                target_bytes: $t['total_bytes'],
                count_delta: $count_delta,
                bytes_delta: $bytes_delta,
            );
        }

        // Sort by absolute byte delta — biggest movers first.
        usort(
            $deltas,
            static fn(BinDelta $a, BinDelta $b) => abs($b->bytes_delta) <=> abs($a->bytes_delta),
        );

        return $deltas;
    }

    /**
     * Synthetic "heap grew but the typed allocations didn't" finding (B.4
     * in the orphan-allocation design). The signal is the design's North
     * Star: when this fires, the user knows the leak is C-extension-side
     * emalloc territory and should consult the bin histogram delta.
     *
     * Threshold: heap_usage delta clears the smaller of 100 KiB or 1% of
     * baseline. Lower bar than fragmentation findings on purpose — even a
     * small heap growth with a flat type breakdown is worth surfacing.
     *
     * @param list<SummaryDelta> $summary_deltas
     * @param list<TypeDelta> $type_deltas
     */
    private function detectUnaccountedDelta(
        array $summary_deltas,
        array $type_deltas,
    ): ?Finding {
        $heap_delta = null;
        $heap_baseline = null;
        foreach ($summary_deltas as $sd) {
            if ($sd->metric === 'zend_mm_heap_usage') {
                $heap_delta = (int)$sd->delta;
                $heap_baseline = (int)$sd->baseline;
                break;
            }
        }
        if ($heap_delta === null || $heap_delta <= 0) {
            return null;
        }

        $abs_threshold = 100 * 1024;
        $rel_threshold = $heap_baseline !== null && $heap_baseline > 0
            ? (int)floor(Cast::toFloat($heap_baseline) * 0.01)
            : $abs_threshold;
        $threshold = min($abs_threshold, $rel_threshold);
        if ($heap_delta < $threshold) {
            return null;
        }

        $type_delta_sum = 0;
        $type_delta_abs_sum = 0;
        foreach ($type_deltas as $td) {
            $type_delta_sum += $td->memory_delta;
            $type_delta_abs_sum += abs($td->memory_delta);
        }

        // "Approximately zero" relative to the heap movement: typed deltas
        // explain less than 10% of what the heap absorbed.
        if ($type_delta_abs_sum >= (int)floor(Cast::toFloat($heap_delta) * 0.1)) {
            return null;
        }

        return new Finding(
            kind: 'unaccounted_heap_delta',
            severity: FindingSeverity::High,
            confidence: FindingConfidence::High,
            summary: sprintf(
                'Heap grew by %s but typed allocations only moved by %s'
                . ' — likely orphan / C-extension emalloc',
                SizeFormatter::format($heap_delta),
                SizeFormatter::format($type_delta_sum),
            ),
            facts: [
                'heap_usage_delta' => $heap_delta,
                'type_delta_sum' => $type_delta_sum,
                'type_delta_abs_sum' => $type_delta_abs_sum,
            ],
            hypothesis: 'The heap absorbed bytes that no PHP-rooted node claimed.'
                . ' Standard culprits: extension-side emalloc (curl_easy, uv_*, libxml,'
                . ' pdo_stmt) leaking structs that ZendMM tracks but the root walker'
                . ' never reaches.',
            next_checks: [
                'Inspect the bin histogram delta below for the largest +Δ bin',
                'Run inspector:memory:report --no-derived-cache on the target dump'
                    . ' to see the periodic-groups table, which surfaces the leaked shape',
            ],
            impact_bytes: $heap_delta,
        );
    }

    /**
     * @param array<string, int|float> $baseline
     * @param array<string, int|float> $target
     * @return list<SummaryDelta>
     */
    private function compareSummaries(
        array $baseline,
        array $target,
        float $threshold_percent,
    ): array {
        $deltas = [];
        foreach (self::SUMMARY_METRICS as $metric) {
            $b = $baseline[$metric] ?? null;
            $t = $target[$metric] ?? null;
            if ($b === null && $t === null) {
                continue;
            }
            $b = (float)($b ?? 0);
            $t = (float)($t ?? 0);
            $delta = $t - $b;
            $pct = $b != 0.0 ? ($delta / $b) * 100.0 : null;

            if ($threshold_percent > 0.0 && $pct !== null && abs($pct) < $threshold_percent) {
                continue;
            }

            $deltas[] = new SummaryDelta(
                metric: $metric,
                baseline: $b,
                target: $t,
                delta: $delta,
                delta_percent: $pct !== null ? round($pct, 1) : null,
            );
        }

        return $deltas;
    }

    /**
     * @param array<string, array{count: int, memory_usage: int}> $baseline
     * @param array<string, array{count: int, memory_usage: int}> $target
     * @return list<TypeDelta>
     */
    private function compareTypes(
        array $baseline,
        array $target,
        float $threshold_percent,
    ): array {
        $all_types = array_unique(array_merge(
            array_keys($baseline),
            array_keys($target),
        ));

        $deltas = [];
        foreach ($all_types as $type) {
            $b = $baseline[$type] ?? ['count' => 0, 'memory_usage' => 0];
            $t = $target[$type] ?? ['count' => 0, 'memory_usage' => 0];

            $mem_delta = $t['memory_usage'] - $b['memory_usage'];
            $count_delta = $t['count'] - $b['count'];

            if ($mem_delta === 0 && $count_delta === 0) {
                continue;
            }

            if (
                $threshold_percent > 0.0
                && $b['memory_usage'] > 0
                && abs((float)$mem_delta / (float)$b['memory_usage'] * 100.0) < $threshold_percent
            ) {
                continue;
            }

            $deltas[] = new TypeDelta(
                type: $type,
                baseline_count: $b['count'],
                target_count: $t['count'],
                baseline_memory: $b['memory_usage'],
                target_memory: $t['memory_usage'],
                count_delta: $count_delta,
                memory_delta: $mem_delta,
            );
        }

        usort($deltas, fn(TypeDelta $a, TypeDelta $b) => abs($b->memory_delta) <=> abs($a->memory_delta));

        return $deltas;
    }

    /**
     * @param array<string, array{count: int, memory_usage: int}> $baseline
     * @param array<string, array{count: int, memory_usage: int}> $target
     * @return array{list<ClassDelta>, list<ClassDelta>, list<ClassDelta>}
     */
    private function compareClasses(
        array $baseline,
        array $target,
        float $threshold_percent,
    ): array {
        $all_classes = array_unique(array_merge(
            array_keys($baseline),
            array_keys($target),
        ));

        $changes = [];
        $added = [];
        $removed = [];

        foreach ($all_classes as $class) {
            $b = $baseline[$class] ?? null;
            $t = $target[$class] ?? null;

            if ($b !== null && $t !== null) {
                $mem_delta = $t['memory_usage'] - $b['memory_usage'];
                $count_delta = $t['count'] - $b['count'];

                if ($mem_delta === 0 && $count_delta === 0) {
                    continue;
                }

                if (
                    $threshold_percent > 0.0
                    && $b['memory_usage'] > 0
                    && abs((float)$mem_delta / (float)$b['memory_usage'] * 100.0) < $threshold_percent
                ) {
                    continue;
                }

                $changes[] = new ClassDelta(
                    class_name: $class,
                    baseline_count: $b['count'],
                    target_count: $t['count'],
                    baseline_memory: $b['memory_usage'],
                    target_memory: $t['memory_usage'],
                    count_delta: $count_delta,
                    memory_delta: $mem_delta,
                );
            } elseif ($t !== null) {
                $added[] = new ClassDelta(
                    class_name: $class,
                    baseline_count: 0,
                    target_count: $t['count'],
                    baseline_memory: 0,
                    target_memory: $t['memory_usage'],
                    count_delta: $t['count'],
                    memory_delta: $t['memory_usage'],
                );
            } else {
                assert($b !== null);
                $removed[] = new ClassDelta(
                    class_name: $class,
                    baseline_count: $b['count'],
                    target_count: 0,
                    baseline_memory: $b['memory_usage'],
                    target_memory: 0,
                    count_delta: -$b['count'],
                    memory_delta: -$b['memory_usage'],
                );
            }
        }

        // Sort by |memory_delta| descending
        usort($changes, fn(ClassDelta $a, ClassDelta $b) => abs($b->memory_delta) <=> abs($a->memory_delta));
        usort($added, fn(ClassDelta $a, ClassDelta $b) => $b->target_memory <=> $a->target_memory);
        usort($removed, fn(ClassDelta $a, ClassDelta $b) => $b->baseline_memory <=> $a->baseline_memory);

        return [$changes, $added, $removed];
    }

    private function compareFindings(
        ReportResult $baseline,
        ReportResult $target,
    ): FindingsDiff {
        $baseline_actionable = $this->filterActionable($baseline->findings);
        $target_actionable = $this->filterActionable($target->findings);

        $baseline_keyed = [];
        foreach ($baseline_actionable as $f) {
            $key = $this->findingKey($f);
            $baseline_keyed[$key] = $f;
        }

        $target_keyed = [];
        foreach ($target_actionable as $f) {
            $key = $this->findingKey($f);
            $target_keyed[$key] = $f;
        }

        $new = [];
        $changed = [];
        foreach ($target_keyed as $key => $tf) {
            if (isset($baseline_keyed[$key])) {
                $bf = $baseline_keyed[$key];
                if (
                    $bf->severity !== $tf->severity
                    || $bf->impact_bytes !== $tf->impact_bytes
                ) {
                    $changed[] = ['baseline' => $bf, 'target' => $tf];
                }
            } else {
                $new[] = $tf;
            }
        }

        $resolved = [];
        foreach ($baseline_keyed as $key => $bf) {
            if (!isset($target_keyed[$key])) {
                $resolved[] = $bf;
            }
        }

        return new FindingsDiff(
            new: $new,
            resolved: $resolved,
            changed: $changed,
        );
    }

    /**
     * @param list<Finding> $findings
     * @return list<Finding>
     */
    private function filterActionable(array $findings): array
    {
        return array_values(array_filter(
            $findings,
            fn(Finding $f) => !in_array($f->kind, self::RANKING_KINDS, true),
        ));
    }

    private function findingKey(Finding $f): string
    {
        $entity = $this->extractPrimaryEntity($f);
        return $entity !== '' ? "{$f->kind}:{$entity}" : $f->kind;
    }

    private function extractPrimaryEntity(Finding $f): string
    {
        $facts = $f->facts;

        // Try common entity identifier fields
        foreach (['class_name', 'root_name', 'type'] as $key) {
            if (isset($facts[$key]) && is_string($facts[$key])) {
                return $facts[$key];
            }
        }

        // For arrays/strings, use owner_path
        if (isset($facts['owner_path']) && is_string($facts['owner_path']) && $facts['owner_path'] !== '') {
            return $facts['owner_path'];
        }

        // For node-based findings, use node_id
        if (isset($facts['node_id'])) {
            return 'node_' . (string)$facts['node_id'];
        }

        return '';
    }
}
