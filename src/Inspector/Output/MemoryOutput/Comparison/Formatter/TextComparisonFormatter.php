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

namespace Reli\Inspector\Output\MemoryOutput\Comparison\Formatter;

use Reli\Inspector\Output\MemoryOutput\Comparison\ClassDelta;
use Reli\Inspector\Output\MemoryOutput\Comparison\ComparisonResult;
use Reli\Inspector\Output\MemoryOutput\Comparison\SummaryDelta;
use Reli\Inspector\Output\MemoryOutput\Comparison\TypeDelta;
use Reli\Inspector\Output\MemoryOutput\Report\Finding;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\SizeFormatter;

final class TextComparisonFormatter implements ComparisonFormatterInterface
{
    /** @psalm-suppress InvalidOperand */
    #[\Override]
    public function format(ComparisonResult $result): string
    {
        $lines = [];
        $sep = str_repeat('=', 78);

        $lines[] = $sep;
        $lines[] = ' reli-prof Memory Comparison Report';
        $lines[] = $sep;
        $lines[] = '';

        // Meta
        $this->formatMeta($lines, $result);

        // Summary deltas
        $this->formatSummaryDeltas($lines, $result->summary_deltas);

        // Type breakdown deltas
        $this->formatTypeDeltas($lines, $result->type_deltas);

        // Class deltas
        $this->formatClassDeltas($lines, $result);

        // Findings diff
        $this->formatFindingsDiff($lines, $result);

        $lines[] = $sep;

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<string> $lines
     */
    /**
     * @param list<string> $lines
     * @psalm-suppress MixedArrayAccess, MixedOperand, MixedArgument
     */
    private function formatMeta(array &$lines, ComparisonResult $result): void
    {
        /** @var string|null $baseline_at */
        $baseline_at = $result->baseline_meta['captured_at'] ?? null;
        /** @var string|null $target_at */
        $target_at = $result->target_meta['captured_at'] ?? null;

        if ($baseline_at !== null || $target_at !== null) {
            $lines[] = '  Baseline: ' . ($baseline_at ?? '(unknown)');
            $lines[] = '  Target:   ' . ($target_at ?? '(unknown)');
        }

        // Node/edge counts
        $bm = $result->baseline_meta;
        $tm = $result->target_meta;
        $meta_keys = ['node_count', 'edge_count'];
        $has_meta = false;
        foreach ($meta_keys as $key) {
            if (isset($bm[$key]) || isset($tm[$key])) {
                $has_meta = true;
                break;
            }
        }
        if ($has_meta) {
            foreach ($meta_keys as $key) {
                $b = (int)($bm[$key] ?? 0);
                $t = (int)($tm[$key] ?? 0);
                $delta = $t - $b;
                if ($b !== 0 || $t !== 0) {
                    $label = match ($key) {
                        'node_count' => 'Nodes',
                        'edge_count' => 'Edges',
                        default => $key,
                    };
                    $lines[] = sprintf(
                        '  %s: %s → %s (%+d)',
                        $label,
                        number_format($b),
                        number_format($t),
                        $delta,
                    );
                }
            }
        }
        $lines[] = '';
    }

    /**
     * @param list<string> $lines
     * @param list<SummaryDelta> $deltas
     * @psalm-suppress InvalidOperand
     */
    private function formatSummaryDeltas(array &$lines, array $deltas): void
    {
        if ($deltas === []) {
            return;
        }

        $lines[] = '=== Summary Delta ===';
        $lines[] = '';
        $lines[] = sprintf(
            '  %-35s %14s %14s %14s %8s',
            'Metric',
            'Baseline',
            'Target',
            'Delta',
            '',
        );
        $lines[] = '  ' . str_repeat('-', 87);

        foreach ($deltas as $delta) {
            $is_pct_metric = $delta->metric === 'heap_memory_analyzed_percentage';

            if ($is_pct_metric) {
                $baseline_str = sprintf('%.1f%%', $delta->baseline);
                $target_str = sprintf('%.1f%%', $delta->target);
                $delta_str = sprintf('%+.1fpt', $delta->delta);
                $pct_str = '';
            } else {
                $baseline_str = SizeFormatter::format($delta->baseline);
                $target_str = SizeFormatter::format($delta->target);
                $sign = $delta->delta >= 0 ? '+' : '';
                $delta_str = $sign . SizeFormatter::format(abs($delta->delta));
                if ($delta->delta < 0) {
                    $delta_str = '-' . SizeFormatter::format(abs($delta->delta));
                }
                $pct_str = $delta->delta_percent !== null
                    ? sprintf('(%+.1f%%)', $delta->delta_percent)
                    : '';
            }

            $lines[] = sprintf(
                '  %-35s %14s %14s %14s %s',
                $this->metricLabel($delta->metric),
                $baseline_str,
                $target_str,
                $delta_str,
                $pct_str,
            );
        }
        $lines[] = '';
    }

    /**
     * @param list<string> $lines
     * @param list<TypeDelta> $deltas
     * @psalm-suppress InvalidOperand
     */
    private function formatTypeDeltas(array &$lines, array $deltas): void
    {
        if ($deltas === []) {
            return;
        }

        $lines[] = '=== Location Type Delta ===';
        $lines[] = '';
        $lines[] = sprintf(
            '  %-30s %10s %12s %12s %14s',
            'Type',
            "Count \xce\x94",
            'Baseline',
            'Target',
            "Memory \xce\x94",
        );
        $lines[] = '  ' . str_repeat('-', 82);

        foreach ($deltas as $td) {
            $short_type = preg_replace('/^.*\\\\/', '', $td->type) ?? $td->type;
            $short_type = str_replace('MemoryLocation', '', $short_type);
            $count_str = sprintf('%+d', $td->count_delta);
            $mem_sign = $td->memory_delta >= 0 ? '+' : '-';
            $mem_delta_str = $mem_sign . SizeFormatter::format(abs($td->memory_delta));
            $lines[] = sprintf(
                '  %-30s %10s %12s %12s %14s',
                $short_type,
                $count_str,
                SizeFormatter::format($td->baseline_memory),
                SizeFormatter::format($td->target_memory),
                $mem_delta_str,
            );
        }
        $lines[] = '';
    }

    /**
     * @param list<string> $lines
     * @psalm-suppress InvalidOperand
     */
    private function formatClassDeltas(array &$lines, ComparisonResult $result): void
    {
        $changes = array_slice($result->class_changes, 0, 20);
        $added = array_slice($result->class_added, 0, 10);
        $removed = array_slice($result->class_removed, 0, 10);

        if ($changes === [] && $added === [] && $removed === []) {
            return;
        }

        $lines[] = '=== Class Memory Changes ===';
        $lines[] = '';

        if ($changes !== []) {
            $lines[] = sprintf(
                '  %-40s %10s %14s %14s',
                'Class',
                "Count \xce\x94",
                "Memory \xce\x94",
                'Target Memory',
            );
            $lines[] = '  ' . str_repeat('-', 82);

            foreach ($changes as $cd) {
                $display_name = strlen($cd->class_name) > 40
                    ? '...' . substr($cd->class_name, -37)
                    : $cd->class_name;
                $count_str = sprintf('%+d', $cd->count_delta);
                $mem_sign = $cd->memory_delta >= 0 ? '+' : '-';
                $mem_delta_str = $mem_sign . SizeFormatter::format(abs($cd->memory_delta));
                $lines[] = sprintf(
                    '  %-40s %10s %14s %14s',
                    $display_name,
                    $count_str,
                    $mem_delta_str,
                    SizeFormatter::format($cd->target_memory),
                );
            }
            $lines[] = '';
        }

        if ($added !== []) {
            $lines[] = '  New classes (target only):';
            foreach ($added as $cd) {
                $lines[] = sprintf(
                    '    %-40s %8s instances  %s',
                    $cd->class_name,
                    number_format($cd->target_count),
                    SizeFormatter::format($cd->target_memory),
                );
            }
            $lines[] = '';
        }

        if ($removed !== []) {
            $lines[] = '  Removed classes (baseline only):';
            foreach ($removed as $cd) {
                $lines[] = sprintf(
                    '    %-40s %8s instances  %s',
                    $cd->class_name,
                    number_format($cd->baseline_count),
                    SizeFormatter::format($cd->baseline_memory),
                );
            }
            $lines[] = '';
        }
    }

    /**
     * @param list<string> $lines
     */
    private function formatFindingsDiff(array &$lines, ComparisonResult $result): void
    {
        $diff = $result->findings_diff;

        if ($diff->new === [] && $diff->resolved === [] && $diff->changed === []) {
            return;
        }

        $lines[] = '=== Findings Diff ===';
        $lines[] = '';

        if ($diff->new !== []) {
            $lines[] = '  New findings:';
            foreach ($diff->new as $finding) {
                $this->formatFindingLine($lines, $finding, '+');
            }
            $lines[] = '';
        }

        if ($diff->resolved !== []) {
            $lines[] = '  Resolved findings:';
            foreach ($diff->resolved as $finding) {
                $this->formatFindingLine($lines, $finding, '-');
            }
            $lines[] = '';
        }

        if ($diff->changed !== []) {
            $lines[] = '  Changed findings:';
            foreach ($diff->changed as $pair) {
                $bf = $pair['baseline'];
                $tf = $pair['target'];
                $parts = ["    {$tf->kind}:"];
                if ($bf->severity !== $tf->severity) {
                    $parts[] = sprintf(
                        "severity %s → %s",
                        $bf->severity->value,
                        $tf->severity->value,
                    );
                }
                if ($bf->impact_bytes !== $tf->impact_bytes) {
                    $parts[] = sprintf(
                        "impact %s → %s",
                        SizeFormatter::format($bf->impact_bytes),
                        SizeFormatter::format($tf->impact_bytes),
                    );
                }
                $lines[] = implode(' ', $parts);
            }
            $lines[] = '';
        }
    }

    /**
     * @param list<string> $lines
     */
    private function formatFindingLine(array &$lines, Finding $f, string $prefix): void
    {
        $tag = strtoupper($f->severity->value);
        $impact = $f->impact_bytes > 0
            ? SizeFormatter::format($f->impact_bytes)
            : "—";
        $lines[] = "    {$prefix} [{$tag}] {$impact}: {$f->kind}: {$f->summary}";
    }

    private function metricLabel(string $metric): string
    {
        return match ($metric) {
            'memory_get_usage' => 'memory_get_usage()',
            'memory_get_real_usage' => 'memory_get_usage(true)',
            'memory_get_peak_usage' => 'peak usage',
            'memory_limit' => 'memory_limit',
            'zend_mm_heap_total' => 'Heap total',
            'zend_mm_heap_usage' => 'Heap usage',
            'vm_stack_total' => 'VM stack',
            'vm_stack_usage' => 'VM stack usage',
            'compiler_arena_total' => 'Compiler arena',
            'compiler_arena_usage' => 'Compiler arena usage',
            'heap_memory_analyzed_percentage' => 'Analyzed',
            'rss' => 'RSS',
            default => $metric,
        };
    }
}
