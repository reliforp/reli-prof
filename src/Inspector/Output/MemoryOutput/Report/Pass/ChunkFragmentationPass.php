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

/**
 * Reports on chunk-level fragmentation.
 *
 * A ZendMM chunk can only be returned to the OS when every page inside it is
 * free; one long-lived allocation is enough to pin the entire 2 MB. This pass
 * looks at aggregated free-page statistics collected during the chunk walk
 * and surfaces both the total scattered free-page space and the count of
 * chunks that are almost empty but cannot be released.
 */
final class ChunkFragmentationPass implements PassInterface
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
        $flat = [];
        foreach ($this->summary as $entry) {
            foreach ($entry as $key => $value) {
                $flat[$key] = $value;
            }
        }

        $chunks_count = (int)($flat['chunks_count'] ?? 0);
        $total_free_bytes = (int)($flat['chunks_total_free_bytes'] ?? 0);
        $mostly_empty_count = (int)($flat['chunks_mostly_empty_count'] ?? 0);
        $heap_usage = (int)($flat['zend_mm_heap_usage'] ?? 0);

        if ($chunks_count === 0) {
            return [];
        }

        $findings = [];

        // Info line: always emit so users can see the raw fragmentation signal.
        $findings[] = new Finding(
            kind: 'zendmm_chunk_fragmentation_state',
            severity: FindingSeverity::Info,
            confidence: FindingConfidence::High,
            summary: sprintf(
                'ZendMM fragmentation: %s of free space scattered across %d in-use chunks'
                . ' (%d chunks are ≥90%% empty but pinned)',
                SizeFormatter::format($total_free_bytes),
                $chunks_count,
                $mostly_empty_count,
            ),
            facts: [
                'chunks_count' => $chunks_count,
                'chunks_total_free_bytes' => $total_free_bytes,
                'chunks_mostly_empty_count' => $mostly_empty_count,
            ],
            replay_query: "SELECT key, value FROM summary WHERE key IN"
                . " ('chunks_count', 'chunks_total_free_bytes', 'chunks_mostly_empty_count')",
        );

        // Pinned mostly-empty chunks — the "one allocation holds 2 MB hostage"
        // pattern. Even one is worth mentioning because gc_mem_caches() won't
        // release these.
        if ($mostly_empty_count > 0) {
            $severity = $mostly_empty_count >= 4
                ? FindingSeverity::Medium
                : FindingSeverity::Warning;
            $findings[] = new Finding(
                kind: 'zendmm_chunks_pinned_by_fragmentation',
                severity: $severity,
                confidence: FindingConfidence::Medium,
                summary: sprintf(
                    '%d in-use chunk%s ≥90%% empty but cannot be returned to the OS',
                    $mostly_empty_count,
                    $mostly_empty_count === 1 ? ' is' : 's are',
                ),
                facts: [
                    'chunks_mostly_empty_count' => $mostly_empty_count,
                    'chunks_count' => $chunks_count,
                ],
                hypothesis: 'A small number of long-lived allocations are scattered across these chunks,'
                    . ' preventing ZendMM from releasing them. gc_mem_caches() does not help here'
                    . ' because the chunks are still in the in-use list, not the cache.',
                next_checks: [
                    'Inspect locations inside these chunks to identify the pinning allocations'
                        . ' (interned strings, persistent class entries, long-lived HashTable buckets)',
                    'Consider restarting long-lived workers between heavy jobs',
                ],
                impact_bytes: $mostly_empty_count * 2 * 1024 * 1024,
            );
        }

        // Large total free-page area relative to heap usage: heap is bloated
        // by per-chunk free gaps.
        if ($heap_usage > 0 && $total_free_bytes > $heap_usage) {
            $findings[] = new Finding(
                kind: 'zendmm_heap_fragmentation_high',
                severity: FindingSeverity::Medium,
                confidence: FindingConfidence::Medium,
                summary: sprintf(
                    'Free-page space inside in-use chunks (%s) exceeds analyzed heap usage (%s)',
                    SizeFormatter::format($total_free_bytes),
                    SizeFormatter::format($heap_usage),
                ),
                facts: [
                    'chunks_total_free_bytes' => $total_free_bytes,
                    'heap_usage' => $heap_usage,
                    'chunks_count' => $chunks_count,
                ],
                hypothesis: 'Live allocations are spread thin across many chunks;'
                    . ' compaction would reclaim substantial memory but PHP cannot move allocations.',
                next_checks: [
                    'Check whether this is a long-running worker that processed a memory peak earlier',
                    'If possible, recycle workers after large transient allocations',
                ],
                impact_bytes: $total_free_bytes,
            );
        }

        return $findings;
    }
}
