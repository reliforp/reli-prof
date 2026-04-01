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

final class OverviewPass implements PassInterface
{
    /** @param array<int, array<string, mixed>> $summary */
    public function __construct(
        private array $summary,
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

        $findings[] = new Finding(
            kind: 'overview',
            severity: FindingSeverity::Info,
            confidence: FindingConfidence::High,
            summary: sprintf(
                'Heap: %.2f MB (%.1f%% analyzed), VM stack: %.2f MB, Compiler arena: %.2f MB',
                $heap_usage / 1024 / 1024,
                $analyzed_pct,
                $vm_stack / 1024 / 1024,
                $compiler_arena / 1024 / 1024,
            ),
            facts: [
                'heap_total' => $heap_total,
                'heap_usage' => $heap_usage,
                'vm_stack_total' => $vm_stack,
                'compiler_arena_total' => $compiler_arena,
                'analyzed_percentage' => $analyzed_pct,
            ],
            replay_query: "SELECT key, value FROM summary WHERE key IN ('zend_mm_heap_total', 'zend_mm_heap_usage', 'vm_stack_total', 'compiler_arena_total', 'heap_memory_analyzed_percentage')",
        );

        if ($analyzed_pct > 0 && $analyzed_pct < 95.0) {
            $gap_bytes = (int)($heap_usage * (100.0 - $analyzed_pct) / 100.0);
            $findings[] = new Finding(
                kind: 'coverage_gap',
                severity: FindingSeverity::Warning,
                confidence: FindingConfidence::Medium,
                summary: sprintf(
                    'Only %.1f%% of heap analyzed — %.2f MB unaccounted',
                    $analyzed_pct,
                    $gap_bytes / 1024 / 1024,
                ),
                facts: [
                    'analyzed_percentage' => $analyzed_pct,
                    'gap_bytes' => $gap_bytes,
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
