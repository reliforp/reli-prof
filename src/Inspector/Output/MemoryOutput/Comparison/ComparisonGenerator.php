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

use Reli\Inspector\Output\MemoryOutput\Report\Finding;
use Reli\Inspector\Output\MemoryOutput\Report\ReportGenerator;
use Reli\Inspector\Output\MemoryOutput\Report\ReportResult;

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
        'root_blame',
        'retained_exact',
        'retained_approximate',
    ];

    public function compare(
        \PDO $baseline_db,
        \PDO $target_db,
        int $baseline_run_id = 1,
        int $target_run_id = 1,
        float $threshold_percent = 0.0,
        bool $full_analysis = false,
        ?bool $ffi_csr = null,
    ): ComparisonResult {
        $generator = new ReportGenerator();

        $baseline_report = $generator->generateFromDb(
            $baseline_db,
            $baseline_run_id,
            $full_analysis,
            $ffi_csr,
        );
        $target_report = $generator->generateFromDb(
            $target_db,
            $target_run_id,
            $full_analysis,
            $ffi_csr,
        );

        $summary_deltas = $this->compareSummaries(
            $baseline_db,
            $target_db,
            $baseline_run_id,
            $target_run_id,
            $threshold_percent,
        );

        [$class_changes, $class_added, $class_removed] = $this->compareClasses(
            $baseline_db,
            $target_db,
            $baseline_run_id,
            $target_run_id,
            $threshold_percent,
        );

        $findings_diff = $this->compareFindings(
            $baseline_report,
            $target_report,
        );

        return new ComparisonResult(
            baseline_meta: $baseline_report->meta,
            target_meta: $target_report->meta,
            summary_deltas: $summary_deltas,
            class_changes: $class_changes,
            class_added: $class_added,
            class_removed: $class_removed,
            findings_diff: $findings_diff,
        );
    }

    /**
     * @return list<SummaryDelta>
     * @psalm-suppress MixedAssignment, MixedArgument, MixedArrayAccess
     */
    private function compareSummaries(
        \PDO $baseline_db,
        \PDO $target_db,
        int $baseline_run_id,
        int $target_run_id,
        float $threshold_percent,
    ): array {
        $baseline = $this->loadSummaryMap($baseline_db, $baseline_run_id);
        $target = $this->loadSummaryMap($target_db, $target_run_id);

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
     * @return array{list<ClassDelta>, list<ClassDelta>, list<ClassDelta>}
     * @psalm-suppress MixedAssignment, MixedArgument, MixedArrayAccess
     */
    private function compareClasses(
        \PDO $baseline_db,
        \PDO $target_db,
        int $baseline_run_id,
        int $target_run_id,
        float $threshold_percent,
    ): array {
        $baseline = $this->loadClassMap($baseline_db, $baseline_run_id);
        $target = $this->loadClassMap($target_db, $target_run_id);

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

    /**
     * @return array<string, int|float>
     * @psalm-suppress MixedAssignment, MixedArgument
     */
    private function loadSummaryMap(\PDO $db, int $run_id): array
    {
        $rows = $db->query(
            "SELECT key, value FROM summary WHERE run_id = {$run_id}"
        )->fetchAll(\PDO::FETCH_KEY_PAIR);

        $result = [];
        foreach ($rows as $key => $value) {
            if (is_numeric($value)) {
                $result[(string)$key] = str_contains((string)$value, '.')
                    ? (float)$value
                    : (int)$value;
            }
        }
        return $result;
    }

    /**
     * @return array<string, array{count: int, memory_usage: int}>
     * @psalm-suppress MixedAssignment, MixedArgument, MixedArrayAccess
     */
    private function loadClassMap(\PDO $db, int $run_id): array
    {
        $rows = $db->query(
            "SELECT class_name, count, memory_usage FROM class_objects_summary"
            . " WHERE run_id = {$run_id}"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['class_name']] = [
                'count' => (int)$row['count'],
                'memory_usage' => (int)$row['memory_usage'],
            ];
        }
        return $result;
    }
}
