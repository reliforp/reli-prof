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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader;

use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\MemoryLocations;

/**
 * Snapshot of the address ranges reli observed at analyze time.
 *
 * One row per (kind, start_address) tuple from the four region kinds the
 * collector tracks: ZendMM chunks, huge allocations, VM stacks,
 * and compiler arena chains. Persisted in the rmem summary so the
 * comparison path can diff "added/grown/shrunk/removed" ranges across
 * snapshots — the design's region-map delta (B.2). Without it the user
 * has to text-diff two `inspector:memory:dump:inspect` runs by hand,
 * which is the manual workflow the issue #88 investigation flagged.
 */
final class RegionMap
{
    public const KIND_CHUNK = 'chunk';
    public const KIND_HUGE = 'huge';
    public const KIND_VM_STACK = 'vm_stack';
    public const KIND_COMPILER_ARENA = 'compiler_arena';

    /**
     * @param list<array{kind: string, address: int, size: int}> $entries
     */
    public function __construct(
        public readonly array $entries,
    ) {
    }

    public static function fromCollectedMemories(CollectedMemories $cm): self
    {
        $entries = [];
        self::extract($entries, $cm->chunk_memory_locations, self::KIND_CHUNK);
        self::extract($entries, $cm->huge_memory_locations, self::KIND_HUGE);
        self::extract($entries, $cm->vm_stack_memory_locations, self::KIND_VM_STACK);
        self::extract($entries, $cm->compiler_arena_memory_locations, self::KIND_COMPILER_ARENA);
        return new self($entries);
    }

    /**
     * @param list<array{kind: string, address: int, size: int}> $entries
     * @param-out list<array{kind: string, address: int, size: int}> $entries
     */
    private static function extract(array &$entries, MemoryLocations $locations, string $kind): void
    {
        foreach ($locations->memory_locations as $loc) {
            if ($loc->size <= 0) {
                continue;
            }
            $entries[] = [
                'kind' => $kind,
                'address' => $loc->address,
                'size' => $loc->size,
            ];
        }
    }

    /**
     * @return list<array{kind: string, address: int, size: int}>
     */
    public function toSummaryArray(): array
    {
        return $this->entries;
    }
}
