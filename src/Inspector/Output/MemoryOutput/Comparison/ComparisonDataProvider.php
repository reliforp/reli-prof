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

    public function generateReport(bool $full_analysis, ?bool $ffi_csr): ReportResult;
}
