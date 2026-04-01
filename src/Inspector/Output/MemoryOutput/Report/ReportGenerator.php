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

namespace Reli\Inspector\Output\MemoryOutput\Report;

use Reli\Inspector\Output\MemoryOutput\Report\Pass\BlameAllocationPass;
use Reli\Inspector\Output\MemoryOutput\Report\Pass\ChokePointPass;
use Reli\Inspector\Output\MemoryOutput\Report\Pass\ClassRankingPass;
use Reli\Inspector\Output\MemoryOutput\Report\Pass\CompanionDetectionPass;
use Reli\Inspector\Output\MemoryOutput\Report\Pass\CycleClusterPass;
use Reli\Inspector\Output\MemoryOutput\Report\Pass\DrillDownPass;
use Reli\Inspector\Output\MemoryOutput\Report\Pass\DynamicPropertiesPass;
use Reli\Inspector\Output\MemoryOutput\Report\Pass\NonTreeEdgePass;
use Reli\Inspector\Output\MemoryOutput\Report\Pass\OverviewPass;
use Reli\Inspector\Output\MemoryOutput\Report\Pass\PerPropertyMemoryPass;
use Reli\Inspector\Output\MemoryOutput\Report\Pass\RetainedSizeConfidencePass;
use Reli\Inspector\Output\MemoryOutput\Report\Pass\StructuralDedupPass;
use Reli\Inspector\Output\MemoryOutput\Report\Pass\TopArraysPass;
use Reli\Inspector\Output\MemoryOutput\Report\Pass\TopStringsPass;
use Reli\Inspector\Output\MemoryOutput\Report\Pass\TypeBreakdownPass;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\GraphSubstrate;

final class ReportGenerator
{
    /**
     * Generate a report from an existing SQLite database.
     *
     * @return ReportResult
     */
    public function generateFromDb(\PDO $db, int $run_id = 1): ReportResult
    {
        $meta = $this->loadMeta($db, $run_id);
        $node_count = (int)($meta['node_count'] ?? 0);

        // Phase 1: Summary-based passes (always run)
        $summary = $this->loadSummary($db, $run_id);
        $location_types = $this->loadLocationTypes($db, $run_id);
        $class_objects = $this->loadClassObjects($db, $run_id);

        $findings = [];
        $findings = array_merge($findings, (new OverviewPass($summary))->analyze());
        $findings = array_merge($findings, (new TypeBreakdownPass($location_types))->analyze());
        $findings = array_merge($findings, (new ClassRankingPass($class_objects))->analyze());
        $findings = array_merge($findings, (new CompanionDetectionPass($class_objects))->analyze());

        // Phase 2: SQL-based passes (< 500K nodes)
        if ($node_count < 500000) {
            $findings = array_merge($findings, (new DynamicPropertiesPass($db, $run_id))->analyze());
            $findings = array_merge($findings, (new PerPropertyMemoryPass($db, $run_id))->analyze());
            $findings = array_merge($findings, (new TopArraysPass($db, $run_id))->analyze());
            $findings = array_merge($findings, (new TopStringsPass($db, $run_id))->analyze());
            $findings = array_merge($findings, (new NonTreeEdgePass($db, $run_id))->analyze());
            $findings = array_merge($findings, (new StructuralDedupPass($db, $run_id))->analyze());
        }

        // Phase 3: Graph-based passes (< 500K edges)
        $edge_count = (int)($meta['edge_count'] ?? 0);
        if ($edge_count > 0 && $edge_count < 500000) {
            $substrate = GraphSubstrate::loadFromDb($db, $run_id);
            $meta['scc_count'] = count($substrate->scc_profiles);

            $findings = array_merge($findings, (new CycleClusterPass($substrate))->analyze());
            $findings = array_merge($findings, (new DrillDownPass($substrate, $db, $run_id))->analyze());
            $findings = array_merge($findings, (new ChokePointPass($substrate, $db, $run_id))->analyze());
            $findings = array_merge($findings, (new BlameAllocationPass($substrate, $db, $run_id))->analyze());
            $findings = array_merge($findings, (new RetainedSizeConfidencePass($substrate))->analyze());
        }

        return new ReportResult($meta, $findings);
    }

    /**
     * @return array<string, mixed>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument, MixedReturnTypeCoercion, MixedArrayOffset
     */
    private function loadMeta(\PDO $db, int $run_id): array
    {
        $meta = [];

        // From summary table
        $rows = $db->query(
            "SELECT key, value FROM summary WHERE run_id = {$run_id}"
        )->fetchAll(\PDO::FETCH_KEY_PAIR);

        $meta['php_version'] = $rows['php_version'] ?? null;
        $meta['heap_memory_analyzed_percentage'] = isset($rows['heap_memory_analyzed_percentage'])
            ? (float)$rows['heap_memory_analyzed_percentage']
            : null;
        $meta['memory_get_usage'] = isset($rows['memory_get_usage'])
            ? (int)$rows['memory_get_usage']
            : null;

        // Node and edge counts
        $stmt = $db->query(
            "SELECT count(*) FROM context_nodes WHERE run_id = {$run_id}"
        );
        $meta['node_count'] = (int)$stmt->fetchColumn();

        $stmt = $db->query(
            "SELECT count(*) FROM context_edges WHERE run_id = {$run_id}"
        );
        $meta['edge_count'] = (int)$stmt->fetchColumn();

        return $meta;
    }

    /**
     * @return array<int, array<string, mixed>>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument, MixedOperand
     * @psalm-suppress MixedReturnTypeCoercion, TypeDoesNotContainType, InvalidArgument, MixedArrayOffset
     */
    private function loadSummary(\PDO $db, int $run_id): array
    {
        $rows = $db->query(
            "SELECT key, value FROM summary WHERE run_id = {$run_id}"
        )->fetchAll(\PDO::FETCH_KEY_PAIR);

        $flat = [];
        foreach ($rows as $key => $value) {
            $flat[$key] = is_numeric($value) ? (str_contains($value, '.') ? (float)$value : (int)$value) : $value;
        }
        return [$flat];
    }

    /**
     * @return array<string, array{count: int, memory_usage: int}>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument, MixedReturnTypeCoercion, MixedArrayOffset
     */
    private function loadLocationTypes(\PDO $db, int $run_id): array
    {
        $rows = $db->query(
            "SELECT type, count, memory_usage FROM location_types_summary"
            . " WHERE run_id = {$run_id} ORDER BY memory_usage DESC"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[$row['type']] = [
                'count' => (int)$row['count'],
                'memory_usage' => (int)$row['memory_usage'],
            ];
        }
        return $result;
    }

    /**
     * @return array<string, array{count: int, memory_usage: int}>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument, MixedReturnTypeCoercion, MixedArrayOffset
     */
    private function loadClassObjects(\PDO $db, int $run_id): array
    {
        $rows = $db->query(
            "SELECT class_name, count, memory_usage FROM class_objects_summary"
            . " WHERE run_id = {$run_id} ORDER BY memory_usage DESC"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[$row['class_name']] = [
                'count' => (int)$row['count'],
                'memory_usage' => (int)$row['memory_usage'],
            ];
        }
        return $result;
    }
}
