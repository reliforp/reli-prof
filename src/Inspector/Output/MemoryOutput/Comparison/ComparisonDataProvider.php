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

use Reli\Inspector\Output\MemoryOutput\Report\ReportResult;

/**
 * Abstraction for data sources used by ComparisonGenerator.
 *
 * Implementations read summary metrics, type/class breakdowns, and
 * generate full reports from either a PDO (SQLite) database or a
 * .rmem binary file.
 */
interface ComparisonDataProvider
{
    /**
     * @return array<string, int|float>
     */
    public function loadSummaryMap(): array;

    /**
     * @return array<string, array{count: int, memory_usage: int}>
     */
    public function loadTypeMap(): array;

    /**
     * @return array<string, array{count: int, memory_usage: int}>
     */
    public function loadClassMap(): array;

    /**
     * Decoded bin walker output from the summary section, or null when the
     * snapshot pre-dates the bin walker / the ZendMM walk failed.
     *
     * @return array{
     *     histogram: array<int, array{count: int, total_bytes: int}>,
     *     large_run_count?: int,
     *     large_run_bytes?: int,
     *     live_small_slot_count?: int,
     *     live_small_slot_bytes?: int,
     *     walked_chunk_count?: int,
     *     partial?: bool
     * }|null
     */
    public function loadBinHistogramSnapshot(): ?array;

    /**
     * The address-range snapshot the analyze pipeline persisted via
     * {@see \Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionMap}.
     *
     * @return list<array{kind: string, address: int, size: int}>|null
     */
    public function loadRegionMap(): ?array;

    public function generateReport(bool $full_analysis, ?bool $ffi_csr): ReportResult;
}
