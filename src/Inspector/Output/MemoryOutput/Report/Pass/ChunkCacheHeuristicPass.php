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
 * Reports on ZendMM's chunk-delete heuristic state.
 *
 * ZendMM keeps a cache of freed chunks to avoid thrashing the OS allocator,
 * and grows that cache (`cached_chunks_max`) when it detects repeated
 * deletions at the same chunk-count boundary — the signal is
 * `last_chunks_delete_count` reaching 4 while sitting at
 * `last_chunks_delete_boundary`. This pass surfaces that state, and flags
 * cache bloat where the cached-but-unused portion is a large fraction of
 * live chunks.
 */
final class ChunkCacheHeuristicPass implements PassInterface
{
    private const DELETE_COUNT_BUMP_THRESHOLD = 4;

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
        $peak_chunks_count = (int)($flat['peak_chunks_count'] ?? 0);
        $cached_chunks_count = (int)($flat['cached_chunks_count'] ?? 0);
        $cached_chunks_size = (int)($flat['cached_chunks_size'] ?? 0);
        $last_boundary = (int)($flat['last_chunks_delete_boundary'] ?? 0);
        $last_delete_count = (int)($flat['last_chunks_delete_count'] ?? 0);

        // No chunk data (e.g. summary produced by an older Reli version).
        if ($chunks_count === 0 && $peak_chunks_count === 0 && $cached_chunks_count === 0) {
            return [];
        }

        $findings = [];

        $total_chunks = $chunks_count + $cached_chunks_count;
        $facts = [
            'chunks_count' => $chunks_count,
            'peak_chunks_count' => $peak_chunks_count,
            'cached_chunks_count' => $cached_chunks_count,
            'cached_chunks_size' => $cached_chunks_size,
            'last_chunks_delete_boundary' => $last_boundary,
            'last_chunks_delete_count' => $last_delete_count,
        ];

        $findings[] = new Finding(
            kind: 'zendmm_chunk_heuristic_state',
            severity: FindingSeverity::Info,
            confidence: FindingConfidence::High,
            summary: sprintf(
                'ZendMM chunks: %d in-use, %d cached (%s), peak %d;'
                . ' chunk-delete heuristic at %d/%d on boundary %d',
                $chunks_count,
                $cached_chunks_count,
                SizeFormatter::format($cached_chunks_size),
                $peak_chunks_count,
                $last_delete_count,
                self::DELETE_COUNT_BUMP_THRESHOLD,
                $last_boundary,
            ),
            facts: $facts,
            replay_query: "SELECT key, value FROM summary WHERE key IN"
                . " ('chunks_count', 'peak_chunks_count', 'cached_chunks_count',"
                . " 'cached_chunks_size', 'last_chunks_delete_boundary',"
                . " 'last_chunks_delete_count')",
        );

        // Heuristic is about to bump cached_chunks_max — the next free at the
        // same boundary will keep the chunk cached rather than returning it.
        if ($last_delete_count >= self::DELETE_COUNT_BUMP_THRESHOLD - 1) {
            $findings[] = new Finding(
                kind: 'zendmm_cache_expansion_imminent',
                severity: FindingSeverity::Warning,
                confidence: FindingConfidence::Medium,
                summary: sprintf(
                    'ZendMM has seen %d chunk deletions at boundary %d'
                    . ' (threshold %d): cached_chunks_max will grow on the next free',
                    $last_delete_count,
                    $last_boundary,
                    self::DELETE_COUNT_BUMP_THRESHOLD,
                ),
                facts: $facts,
                hypothesis: 'Workload is cycling through a stable working set of chunks;'
                    . ' ZendMM is about to keep more chunks cached instead of returning to OS,'
                    . ' so RSS will stay elevated between bursts',
                next_checks: [
                    'Check whether this is a long-lived CLI / worker process',
                    'Call gc_mem_caches() between jobs to drop cached chunks back to the OS',
                ],
            );
        }

        // Cache bloat: cached chunks dominate vs. live working set.
        if (
            $total_chunks >= 4
            && $cached_chunks_count >= $chunks_count
            && $cached_chunks_count >= 2
        ) {
            $findings[] = new Finding(
                kind: 'zendmm_cache_bloat',
                severity: FindingSeverity::Medium,
                confidence: FindingConfidence::Medium,
                summary: sprintf(
                    'ZendMM is holding more cached chunks (%d, %s) than in-use chunks (%d)',
                    $cached_chunks_count,
                    SizeFormatter::format($cached_chunks_size),
                    $chunks_count,
                ),
                facts: $facts,
                hypothesis: 'Past peaks bumped cached_chunks_max and the cache has not shrunk;'
                    . ' cached chunks are not returned to the OS until shutdown or gc_mem_caches()',
                next_checks: [
                    'Call gc_mem_caches() periodically to release cached chunks',
                    'Compare cached_chunks_size against RSS to confirm the OS-level impact',
                ],
                impact_bytes: $cached_chunks_size,
            );
        }

        return $findings;
    }
}
