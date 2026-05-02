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

use Reli\Inspector\Output\MemoryOutput\Report\ReportGenerator;
use Reli\Inspector\Output\MemoryOutput\Report\ReportResult;

final class PdoComparisonDataProvider implements ComparisonDataProvider
{
    public function __construct(
        private \PDO $db,
        private int $run_id = 1,
    ) {
    }

    /**
     * @psalm-suppress MixedAssignment, MixedArgument
     */
    #[\Override]
    public function loadSummaryMap(): array
    {
        $rows = $this->db->query(
            "SELECT key, value FROM summary WHERE run_id = {$this->run_id}"
        )->fetchAll(\PDO::FETCH_KEY_PAIR);

        $result = [];
        foreach ($rows as $key => $value) {
            if (is_numeric($value)) {
                $result[(string)$key] = str_contains((string)$value, '.')
                    ? (float)$value
                    : (int)$value;
            }
        }
        return $result;
    }

    /**
     * @psalm-suppress MixedAssignment, MixedArgument, MixedArrayAccess
     */
    #[\Override]
    public function loadTypeMap(): array
    {
        $rows = $this->db->query(
            "SELECT type, count, memory_usage FROM location_types_summary"
            . " WHERE run_id = {$this->run_id}"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['type']] = [
                'count' => (int)$row['count'],
                'memory_usage' => (int)$row['memory_usage'],
            ];
        }
        return $result;
    }

    /**
     * @psalm-suppress MixedAssignment, MixedArgument, MixedArrayAccess
     */
    #[\Override]
    public function loadClassMap(): array
    {
        $rows = $this->db->query(
            "SELECT class_name, count, memory_usage FROM class_objects_summary"
            . " WHERE run_id = {$this->run_id}"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['class_name']] = [
                'count' => (int)$row['count'],
                'memory_usage' => (int)$row['memory_usage'],
            ];
        }
        return $result;
    }

    /**
     * @psalm-suppress MixedAssignment
     */
    #[\Override]
    public function loadBinHistogramSnapshot(): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT value FROM summary WHERE run_id = :run_id AND key = 'bin_walk'"
        );
        $stmt->execute([':run_id' => $this->run_id]);
        $value = $stmt->fetchColumn();
        if (!is_string($value) || $value === '') {
            return null;
        }
        return BinHistogramSnapshot::decode($value);
    }

    /**
     * @psalm-suppress MixedAssignment
     */
    #[\Override]
    public function loadRegionMap(): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT value FROM summary WHERE run_id = :run_id AND key = 'region_map'"
        );
        $stmt->execute([':run_id' => $this->run_id]);
        $value = $stmt->fetchColumn();
        if (!is_string($value) || $value === '') {
            return null;
        }
        return RegionMapSnapshot::decode($value);
    }

    /**
     * @psalm-suppress MixedAssignment
     */
    #[\Override]
    public function loadBinShapeCounts(): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT value FROM summary WHERE run_id = :run_id AND key = 'bin_shape_counts'"
        );
        $stmt->execute([':run_id' => $this->run_id]);
        $value = $stmt->fetchColumn();
        if (!is_string($value) || $value === '') {
            return null;
        }
        return BinShapeCountsSnapshot::decode($value);
    }

    #[\Override]
    public function generateReport(bool $full_analysis, ?bool $ffi_csr): ReportResult
    {
        $generator = new ReportGenerator();
        return $generator->generateFromDb(
            $this->db,
            $this->run_id,
            $full_analysis,
            $ffi_csr,
        );
    }
}
