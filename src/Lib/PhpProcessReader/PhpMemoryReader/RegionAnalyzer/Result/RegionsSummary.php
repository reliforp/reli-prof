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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\Result;

final class RegionsSummary
{
    public function __construct(
        public int $zend_mm_heap_total,
        public int $zend_mm_heap_usage,
        public int $zend_mm_chunk_total,
        public int $zend_mm_chunk_usage,
        public int $zend_mm_huge_total,
        public int $zend_mm_huge_usage,
        public int $vm_stack_total,
        public int $vm_stack_usage,
        public int $compiler_arena_total,
        public int $compiler_arena_usage,
        public int $possible_allocation_overhead_total,
        public int $possible_array_overhead_total,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'zend_mm_heap_total' => $this->zend_mm_heap_total,
            'zend_mm_heap_usage' => $this->zend_mm_heap_usage,
            'zend_mm_chunk_total' => $this->zend_mm_chunk_total,
            'zend_mm_chunk_usage' => $this->zend_mm_chunk_usage,
            'zend_mm_huge_total' => $this->zend_mm_huge_total,
            'zend_mm_huge_usage' => $this->zend_mm_huge_usage,
            'vm_stack_total' => $this->vm_stack_total,
            'vm_stack_usage' => $this->vm_stack_usage,
            'compiler_arena_total' => $this->compiler_arena_total,
            'compiler_arena_usage' => $this->compiler_arena_usage,
            'possible_allocation_overhead_total' => $this->possible_allocation_overhead_total,
            'possible_array_overhead_total' => $this->possible_array_overhead_total,
        ];
    }

    /**
     * Build a corrected summary array using region sums from the DB.
     *
     * In streaming mode the main memory_locations collection uses lightweight
     * (address-only) tracking, so RegionAnalyzer cannot compute usage from
     * individual locations.  This method reconstructs the usage values from
     * SUM(size) grouped by region in the context_node_locations table.
     *
     * @param array<string, int> $region_sums  region name => SUM(size) from DB
     * @return array<string, int>
     */
    public function correctedToArray(array $region_sums): array
    {
        $db_chunk_usage = $region_sums['zend_mm_heap'] ?? 0;
        $db_huge_usage = $region_sums['zend_mm_huge'] ?? 0;

        $corrected_chunk_usage = $db_chunk_usage
            + $this->possible_allocation_overhead_total
            + $this->vm_stack_total
            + $this->compiler_arena_total;

        return [
            'zend_mm_heap_total' => $this->zend_mm_heap_total,
            'zend_mm_heap_usage' => $corrected_chunk_usage + $db_huge_usage,
            'zend_mm_chunk_total' => $this->zend_mm_chunk_total,
            'zend_mm_chunk_usage' => $corrected_chunk_usage,
            'zend_mm_huge_total' => $this->zend_mm_huge_total,
            'zend_mm_huge_usage' => $db_huge_usage,
            'vm_stack_total' => $this->vm_stack_total,
            'vm_stack_usage' => $region_sums['vm_stack'] ?? 0,
            'compiler_arena_total' => $this->compiler_arena_total,
            'compiler_arena_usage' => $region_sums['compiler_arena'] ?? 0,
            'possible_allocation_overhead_total' => $this->possible_allocation_overhead_total,
            'possible_array_overhead_total' => $this->possible_array_overhead_total,
        ];
    }

    /**
     * Query context_node_locations for SUM(size) grouped by region.
     *
     * @return array<string, int> region name => total size
     */
    public static function queryRegionSums(\PDO $db, int $run_id): array
    {
        $stmt = $db->prepare(
            'SELECT region, COALESCE(SUM(size), 0) AS total_size FROM ('
            . '  SELECT address, region, MAX(size) AS size'
            . '  FROM context_node_locations'
            . '  WHERE run_id = ? AND region IS NOT NULL'
            . '  GROUP BY address, region'
            . ') GROUP BY region'
        );
        $stmt->execute([$run_id]);
        $sums = [];
        /** @psalm-suppress MixedAssignment */
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            /** @psalm-suppress MixedArrayAccess */
            $sums[(string)$row['region']] = (int)$row['total_size'];
        }
        return $sums;
    }
}
