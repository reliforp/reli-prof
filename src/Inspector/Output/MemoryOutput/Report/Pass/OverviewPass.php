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

use Reli\Inspector\Output\MemoryOutput\Report\Finding;
use Reli\Inspector\Output\MemoryOutput\Report\FindingConfidence;
use Reli\Inspector\Output\MemoryOutput\Report\FindingSeverity;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\SizeFormatter;

final class OverviewPass implements PassInterface
{
    /**
     * @param array<int, array<string, mixed>> $summary
     * @param array<string, array{count: int, memory_usage: int}> $location_types_summary
     *     Sum of `memory_usage` across this map is the canonical "bytes
     *     reli attributed to PHP-level nodes via pointer-tracing": it is
     *     built from the same node-set the rest of the report is rendered
     *     from, so the live and offline pipelines produce the same number
     *     for the same target. Used as the displayed Heap value so the
     *     headline figure stops disagreeing with the per-type / per-class
     *     totals immediately below it (see
     *     docs/internals/memory-coverage-survey-2026-05.md G1/G2).
     */
    public function __construct(
        private array $summary,
        private array $location_types_summary = [],
    ) {
    }

    /**
     * @return list<Finding>
     * @psalm-suppress MixedAssignment, MixedArgument, MixedOperand, InvalidOperand
     */
    #[\Override]
    public function analyze(): array
    {
        $findings = [];
        $flat = [];
        foreach ($this->summary as $entry) {
            foreach ($entry as $key => $value) {
                $flat[$key] = $value;
            }
        }

        $heap_total = (int)($flat['zend_mm_heap_total'] ?? 0);
        $heap_usage = (int)($flat['zend_mm_heap_usage'] ?? 0);
        $vm_stack = (int)($flat['vm_stack_total'] ?? 0);
        $compiler_arena = (int)($flat['compiler_arena_total'] ?? 0);
        $analyzed_pct = (float)($flat['heap_memory_analyzed_percentage'] ?? 0);
        $memory_get_usage = (int)($flat['memory_get_usage'] ?? 0);
        $memory_get_real_usage = (int)($flat['memory_get_real_usage'] ?? 0);
        $memory_get_peak_usage = (int)($flat['memory_get_peak_usage'] ?? 0);
        $memory_limit = (int)($flat['memory_limit'] ?? 0);
        $rss = isset($flat['rss']) ? (int)$flat['rss'] : null;

        // Heap and "% analyzed" are intentionally derived from the same
        // node-set the rest of the report is built on — i.e. SUM(memory_usage)
        // across the per-type breakdown. That sum is what every downstream
        // pass (TypeBreakdown, ClassRanking, RootBlame, …) is rendering
        // already; pulling it up into the Overview makes the headline
        // self-consistent with the body.
        //
        // Why not the legacy zend_mm_heap_usage / heap_memory_analyzed_percentage:
        //   - The numerator is slot-rounded (chunk_usage + vm_stack +
        //     compiler_arena + bin overhead) but the denominator
        //     (memory_get_usage(false)) is not, so the ratio can exceed
        //     100% on heaps where slot rounding outweighs unattributed
        //     bytes (laravel, the canonical case).
        //   - The numerator's dedup/overhead handling differs between the
        //     live MemoryCommand path and the offline MemoryDumpReader
        //     path, so the same target reports different Heap on the two
        //     pipelines.
        //
        // Why not the bin walker total (live_small_slot_bytes + large_run_bytes):
        //   - The bin walker is a fallback enumeration of slots reli's
        //     pointer-tracer didn't reach, not the primary "we analyzed
        //     this" signal. Promoting it to the denominator would make
        //     "% analyzed" track slot rounding rather than the depth of
        //     pointer-tracing coverage.
        $attributed_bytes = 0;
        foreach ($this->location_types_summary as $entry) {
            $attributed_bytes += $entry['memory_usage'];
        }

        if ($attributed_bytes > 0 && $memory_get_usage > 0) {
            $display_heap = $attributed_bytes;
            $display_pct = (float)$attributed_bytes / (float)$memory_get_usage * 100.0;
            $analyzed_source = 'pointer_trace';
        } else {
            $display_heap = $heap_usage;
            $display_pct = $analyzed_pct;
            $analyzed_source = 'summary';
        }

        $summary_parts = [
            sprintf('memory_get_usage(): %s', SizeFormatter::format($memory_get_usage)),
            sprintf('memory_get_usage(true): %s', SizeFormatter::format($memory_get_real_usage)),
        ];
        if ($memory_get_peak_usage > 0) {
            $summary_parts[] = sprintf('peak: %s', SizeFormatter::format($memory_get_peak_usage));
        }
        if ($memory_limit > 0) {
            $summary_parts[] = sprintf('memory_limit: %s', SizeFormatter::format($memory_limit));
        }
        if ($rss !== null) {
            $summary_parts[] = sprintf('RSS: %s', SizeFormatter::format($rss));
        }
        $summary_parts[] = sprintf(
            'Heap: %s (%.1f%% analyzed), VM stack: %s, Compiler arena: %s',
            SizeFormatter::format($display_heap),
            $display_pct,
            SizeFormatter::format($vm_stack),
            SizeFormatter::format($compiler_arena),
        );

        $facts = [
            'memory_get_usage' => $memory_get_usage,
            'memory_get_real_usage' => $memory_get_real_usage,
            'memory_get_peak_usage' => $memory_get_peak_usage,
            'memory_limit' => $memory_limit,
            'heap_total' => $heap_total,
            'heap_usage' => $heap_usage,
            'vm_stack_total' => $vm_stack,
            'compiler_arena_total' => $compiler_arena,
            'analyzed_percentage' => $display_pct,
            'analyzed_percentage_source' => $analyzed_source,
            'displayed_heap_bytes' => $display_heap,
        ];
        if ($attributed_bytes > 0) {
            $facts['attributed_bytes'] = $attributed_bytes;
        }
        if ($rss !== null) {
            $facts['rss'] = $rss;
        }

        $findings[] = new Finding(
            kind: 'overview',
            severity: FindingSeverity::Info,
            confidence: FindingConfidence::High,
            summary: implode(' | ', $summary_parts),
            facts: $facts,
            replay_query: "SELECT key, value FROM summary WHERE key IN"
                . " ('zend_mm_heap_total', 'zend_mm_heap_usage',"
                . " 'vm_stack_total', 'compiler_arena_total',"
                . " 'heap_memory_analyzed_percentage',"
                . " 'memory_get_usage', 'memory_get_real_usage',"
                . " 'memory_get_peak_usage', 'memory_limit', 'rss')",
        );

        if ($memory_limit > 0 && $memory_get_peak_usage > 0) {
            $usage_pct = (float)$memory_get_peak_usage / (float)$memory_limit * 100.0;
            if ($usage_pct >= 80.0) {
                $headroom = $memory_limit - $memory_get_peak_usage;
                $findings[] = new Finding(
                    kind: 'near_memory_limit',
                    severity: $usage_pct >= 95.0 ? FindingSeverity::High : FindingSeverity::Warning,
                    confidence: FindingConfidence::High,
                    summary: sprintf(
                        'Peak usage is %.1f%% of memory_limit — only %s headroom',
                        $usage_pct,
                        SizeFormatter::format($headroom),
                    ),
                    facts: [
                        'peak' => $memory_get_peak_usage,
                        'limit' => $memory_limit,
                        'usage_percentage' => $usage_pct,
                        'headroom' => $headroom,
                    ],
                    hypothesis: 'Process is at risk of hitting memory_limit and triggering a fatal error',
                    next_checks: [
                        'Review top memory consumers in this report',
                        'Consider increasing memory_limit or reducing memory usage',
                    ],
                    impact_bytes: $memory_get_peak_usage,
                );
            }
        }

        if ($display_pct > 0 && $display_pct < 95.0) {
            // gap = "bytes reli could not attribute": for the pointer-trace
            // path that's (memory_get_usage - attributed); for the legacy
            // summary path the historic computation was
            // heap_usage * (100 - pct) / 100, so preserve it there.
            if ($analyzed_source === 'pointer_trace') {
                $gap_bytes = max(0, $memory_get_usage - $attributed_bytes);
            } else {
                $gap_bytes = (int)($display_heap * (100.0 - $display_pct) / 100.0);
            }
            $findings[] = new Finding(
                kind: 'coverage_gap',
                severity: FindingSeverity::Warning,
                confidence: FindingConfidence::Medium,
                summary: sprintf(
                    'Only %.1f%% of heap analyzed — %s unaccounted',
                    $display_pct,
                    SizeFormatter::format($gap_bytes),
                ),
                facts: [
                    'analyzed_percentage' => $display_pct,
                    'gap_bytes' => $gap_bytes,
                    'analyzed_percentage_source' => $analyzed_source,
                ],
                hypothesis: 'Unaccounted memory is typically from extension-level emalloc (not PHP objects/arrays)',
                next_checks: [
                    'Check loaded extensions for large non-object allocations',
                    'Look for FFI or custom extension usage',
                ],
                impact_bytes: $gap_bytes,
            );
        }

        return $findings;
    }
}
