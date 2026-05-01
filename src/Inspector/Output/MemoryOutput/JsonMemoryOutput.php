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

namespace Reli\Inspector\Output\MemoryOutput;

use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Reader as BinaryReader;
use Reli\Inspector\Output\MemoryOutput\PdoDriver\SqliteDriver;
use Reli\Inspector\Output\MemoryOutput\Report\BinaryReportDataProvider;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionBoundaries;

final class JsonMemoryOutput implements MemoryOutputInterface
{
    public function __construct(
        private bool $pretty_print = false,
        private ?string $output_path = null,
        private ?RegionBoundaries $region_boundaries = null,
    ) {
    }

    #[\Override]
    public function output(MemoryAnalysisResult $result): void
    {
        if ($result->pre_populated_rmem_path !== null) {
            // Tree was already streamed to .rmem during collection
            $this->streamJsonFromRmem($result->pre_populated_rmem_path, $result);
            return;
        }

        if ($result->pre_populated_db !== null && $result->pre_populated_run_id !== null) {
            // Tree was already streamed to DB during collection
            $this->streamJsonFromDb($result->pre_populated_db, $result->pre_populated_run_id);
            return;
        }

        // Write context tree to a temporary SQLite DB, releasing contexts
        // during the walk so they can be GC'd immediately.
        $tmp_base = tempnam(sys_get_temp_dir(), 'reli_json_');
        if ($tmp_base === false) {
            throw new \RuntimeException('Failed to create temporary file for JSON export');
        }
        $tmp_path = $tmp_base . '.sqlite3';
        @unlink($tmp_base);

        try {
            $sqlite_driver = new SqliteDriver($tmp_path);
            $pdo_output = new PdoMemoryOutput($sqlite_driver, $this->region_boundaries);
            $pdo_output->output($result);

            // Release the in-memory context tree — data is now in SQLite
            unset($result);

            // Stream JSON from the SQLite DB
            $db = new \PDO("sqlite:{$tmp_path}");
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            $run_id = (int)$db->query('SELECT MAX(run_id) FROM runs')->fetchColumn();

            $summary = $this->loadSummary($db, $run_id);
            $location_types_summary = $this->loadLocationTypesSummary($db, $run_id);
            $class_objects_summary = $this->loadClassObjectsSummary($db, $run_id);

            $exporter = new StreamingJsonFromDbExporter($db, $run_id, $this->pretty_print);

            if ($this->output_path !== null) {
                $fp = fopen($this->output_path, 'w');
                if ($fp === false) {
                    throw new \RuntimeException("Cannot open output file: {$this->output_path}");
                }
                try {
                    $exporter->export($summary, $location_types_summary, $class_objects_summary, $fp);
                } finally {
                    fclose($fp);
                }
            } else {
                $fp = fopen('php://stdout', 'w');
                if ($fp === false) {
                    throw new \RuntimeException('Cannot open stdout');
                }
                $exporter->export($summary, $location_types_summary, $class_objects_summary, $fp);
            }
        } finally {
            if (file_exists($tmp_path)) {
                @unlink($tmp_path);
            }
        }
    }

    private function streamJsonFromDb(\PDO $db, int $run_id): void
    {
        $summary = $this->loadSummary($db, $run_id);
        $location_types_summary = $this->loadLocationTypesSummary($db, $run_id);
        $class_objects_summary = $this->loadClassObjectsSummary($db, $run_id);
        $exporter = new StreamingJsonFromDbExporter($db, $run_id, $this->pretty_print);

        $this->exportToTarget(
            fn ($fp) => $exporter->export(
                $summary,
                $location_types_summary,
                $class_objects_summary,
                $fp,
            ),
        );
    }

    private function streamJsonFromRmem(string $rmem_path, MemoryAnalysisResult $result): void
    {
        $reader = BinaryReader::open($rmem_path);
        $summary = $result->summary;
        $location_types_summary = $result->location_types_summary
            ?? BinaryReportDataProvider::computeLocationTypesSummary($reader);
        $class_objects_summary = $result->class_objects_summary
            ?? BinaryReportDataProvider::computeClassObjectsSummary($reader);

        $exporter = new StreamingJsonFromRmemExporter($reader, $this->pretty_print);

        $this->exportToTarget(
            fn ($fp) => $exporter->export(
                $summary,
                $location_types_summary,
                $class_objects_summary,
                $fp,
            ),
        );
    }

    /**
     * @param callable(resource): void $writer
     */
    private function exportToTarget(callable $writer): void
    {
        if ($this->output_path !== null) {
            $fp = fopen($this->output_path, 'w');
            if ($fp === false) {
                throw new \RuntimeException("Cannot open output file: {$this->output_path}");
            }
            try {
                $writer($fp);
            } finally {
                fclose($fp);
            }
        } else {
            $fp = fopen('php://stdout', 'w');
            if ($fp === false) {
                throw new \RuntimeException('Cannot open stdout');
            }
            $writer($fp);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadSummary(\PDO $db, int $run_id): array
    {
        $stmt = $db->prepare(
            'SELECT "key", "value" FROM summary WHERE run_id = ?'
        );
        $stmt->execute([$run_id]);
        /** @var array<string, mixed> $entry */
        $entry = [];
        while (
            /** @var array{key: string, value: string|null} */
            $row = $stmt->fetch(\PDO::FETCH_ASSOC)
        ) {
            /** @psalm-suppress MixedArrayAccess, MixedAssignment */
            $decoded = json_decode((string)$row['value'], true);
            /** @psalm-suppress MixedArrayAccess, MixedAssignment */
            $entry[$row['key']] = $decoded !== null ? $decoded : $row['value'];
        }
        /** @var array<int, array<string, mixed>> */
        return [$entry];
    }

    /**
     * @return array<string, array{count: int, memory_usage: int}>
     */
    private function loadLocationTypesSummary(\PDO $db, int $run_id): array
    {
        $stmt = $db->prepare(
            'SELECT "type", "count", memory_usage FROM location_types_summary WHERE run_id = ?'
        );
        $stmt->execute([$run_id]);
        $result = [];
        while (
            /** @var array{type: string, count: string, memory_usage: string} */
            $row = $stmt->fetch(\PDO::FETCH_ASSOC)
        ) {
            /** @psalm-suppress MixedArrayAccess */
            $result[$row['type']] = [
                'count' => (int)$row['count'],
                'memory_usage' => (int)$row['memory_usage'],
            ];
        }
        /** @var array<string, array{count: int, memory_usage: int}> */
        return $result;
    }

    /**
     * @return array<string, array{count: int, memory_usage: int}>
     */
    private function loadClassObjectsSummary(\PDO $db, int $run_id): array
    {
        $stmt = $db->prepare(
            'SELECT class_name, "count", memory_usage FROM class_objects_summary WHERE run_id = ?'
        );
        $stmt->execute([$run_id]);
        $result = [];
        while (
            /** @var array{class_name: string, count: string, memory_usage: string} */
            $row = $stmt->fetch(\PDO::FETCH_ASSOC)
        ) {
            /** @psalm-suppress MixedArrayAccess */
            $result[$row['class_name']] = [
                'count' => (int)$row['count'],
                'memory_usage' => (int)$row['memory_usage'],
            ];
        }
        /** @var array<string, array{count: int, memory_usage: int}> */
        return $result;
    }
}
