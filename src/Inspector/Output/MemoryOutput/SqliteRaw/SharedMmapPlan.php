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

namespace Reli\Inspector\Output\MemoryOutput\SqliteRaw;

/**
 * Immutable plan describing the page-range layout of a shared-mmap
 * parallel write. Computed once before fork by
 * {@see SharedMmapTableWriter::plan} and passed by value to workers
 * (it inherits cleanly across `pcntl_fork`'s copy-on-write).
 *
 * `worker_leaf_ranges[table][worker] = [first_pgno, count]`
 * `interior_ranges[table] = [first_pgno, count]`
 * `total_pages_used` excludes the leading schema pages already in the
 * file; add it to `first_pgno - 1` to get the file's required total
 * page count after the writer runs.
 */
final class SharedMmapPlan
{
    /**
     * @param array<string, array<int, array{int, int}>> $worker_leaf_ranges
     * @param array<string, array{int, int}> $interior_ranges
     */
    public function __construct(
        private array $worker_leaf_ranges,
        private array $interior_ranges,
        private int $total_pages_used,
        private int $first_pgno,
    ) {
    }

    /** @return array{int, int} [first_pgno, max_pages] */
    public function workerLeafRange(string $table_name, int $worker_index): array
    {
        return $this->worker_leaf_ranges[$table_name][$worker_index];
    }

    /** @return array{int, int} [first_pgno, count] */
    public function interiorRange(string $table_name): array
    {
        return $this->interior_ranges[$table_name];
    }

    public function firstPgno(): int
    {
        return $this->first_pgno;
    }

    public function totalPagesUsed(): int
    {
        return $this->total_pages_used;
    }

    /** @return list<string> */
    public function tableNames(): array
    {
        return array_keys($this->worker_leaf_ranges);
    }

    /**
     * Byte offset of the per-worker meta slot for a given table.
     * Layout: tables iterated in plan order, each table contributes
     * `worker_count * META_BYTES_PER_WORKER_TABLE` bytes.
     */
    public function metaOffsetFor(string $table_name, int $worker_index): int
    {
        $offset = 0;
        foreach ($this->worker_leaf_ranges as $name => $ranges) {
            if ($name === $table_name) {
                return $offset
                    + $worker_index * SharedMmapTableWriter::META_BYTES_PER_WORKER_TABLE;
            }
            $offset += count($ranges) * SharedMmapTableWriter::META_BYTES_PER_WORKER_TABLE;
        }
        throw new \InvalidArgumentException("unknown table: {$table_name}");
    }

    public function metaTotalBytes(): int
    {
        $total = 0;
        foreach ($this->worker_leaf_ranges as $ranges) {
            $total += count($ranges) * SharedMmapTableWriter::META_BYTES_PER_WORKER_TABLE;
        }
        return $total;
    }
}
