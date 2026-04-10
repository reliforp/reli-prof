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

use Reli\Inspector\Output\MemoryOutput\Report\Pass\PassInterface;
use Reli\Inspector\Output\MemoryOutput\Report\Pass\BlameAllocationPass;
use Reli\Inspector\Output\MemoryOutput\Report\Pass\CallStackPass;
use Reli\Inspector\Output\MemoryOutput\Report\Pass\ChokePointPass;
use Reli\Inspector\Output\MemoryOutput\Report\Pass\ClassRankingPass;
use Reli\Inspector\Output\MemoryOutput\Report\Pass\CompanionDetectionPass;
use Reli\Inspector\Output\MemoryOutput\Report\Pass\CycleClusterPass;
use Reli\Inspector\Output\MemoryOutput\Report\Pass\DrillDownPass;
use Reli\Inspector\Output\MemoryOutput\Report\Pass\DynamicPropertiesPass;
use Reli\Inspector\Output\MemoryOutput\Report\Pass\GcPendingPass;
use Reli\Inspector\Output\MemoryOutput\Report\Pass\NonTreeEdgePass;
use Reli\Inspector\Output\MemoryOutput\Report\Pass\OverviewPass;
use Reli\Inspector\Output\MemoryOutput\Report\Pass\OwnershipPatternPass;
use Reli\Inspector\Output\MemoryOutput\Report\Pass\PerPropertyMemoryPass;
use Reli\Inspector\Output\MemoryOutput\Report\Pass\PropertyScalingPass;
use Reli\Inspector\Output\MemoryOutput\Report\Pass\RetainedSizeConfidencePass;
use Reli\Inspector\Output\MemoryOutput\Report\Pass\StructuralDedupPass;
use Reli\Inspector\Output\MemoryOutput\Report\Pass\TopArraysPass;
use Reli\Inspector\Output\MemoryOutput\Report\Pass\TopStringsPass;
use Reli\Inspector\Output\MemoryOutput\Report\Pass\TypeBreakdownPass;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\GraphSubstrate;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\LinkCacheMode;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\LinkNameResolver;

final class ReportGenerator
{
    /**
     * Generate a report from an existing SQLite database.
     *
     * @return ReportResult
     */
    /**
     * @param bool|null $ffi_csr true=force on, false=force off, null=auto
     */
    public function generateFromDb(
        \PDO $db,
        int $run_id = 1,
        bool $full_analysis = false,
        ?bool $ffi_csr = null,
        LinkCacheMode $link_cache_mode = LinkCacheMode::Auto,
    ): ReportResult {
        // Make sure the indexes report passes need exist on this database,
        // even if it was captured by an older Reli version. CREATE INDEX
        // IF NOT EXISTS is a no-op when the index already exists.
        $this->ensureReportIndexes($db);

        $meta = $this->loadMeta($db, $run_id);
        $node_count = (int)($meta['node_count'] ?? 0);
        $edge_count = (int)($meta['edge_count'] ?? 0);

        // Phase 1: Summary-based passes (always run)
        $summary = $this->loadSummary($db, $run_id);
        /** @var array<string, mixed> $flat_summary */
        $flat_summary = [];
        /** @psalm-suppress MixedAssignment */
        foreach ($summary as $entry) {
            foreach ($entry as $k => $v) {
                $flat_summary[$k] = $v;
            }
        }
        $heap_usage = (int)($flat_summary['zend_mm_heap_usage'] ?? 0);
        $location_types = $this->loadLocationTypes($db, $run_id);
        $class_objects = $this->loadClassObjects($db, $run_id);

        $findings = [];
        $findings = array_merge($findings, (new OverviewPass($summary))->analyze());
        $findings = array_merge($findings, (new TypeBreakdownPass($location_types))->analyze());
        $findings = array_merge($findings, (new ClassRankingPass($class_objects))->analyze());
        $findings = array_merge($findings, (new CompanionDetectionPass($class_objects))->analyze());

        // Phase 2: SQL-based passes (< 500K nodes, or --full-analysis)
        $run_phase3 = $full_analysis ? $edge_count > 0 : ($edge_count > 0 && $edge_count < 500000);
        if ($full_analysis || $node_count < 500000) {
            $findings = array_merge($findings, $this->runPass(new CallStackPass($db, $run_id)));
            $findings = array_merge($findings, $this->runPass(new DynamicPropertiesPass($db, $run_id)));
            // PropertyScaling, TopArrays, TopStrings: deferred to Phase 3
            // if graph available (full path + retained size)
            if (!$run_phase3) {
                $findings = array_merge($findings, $this->runPass(
                    new PropertyScalingPass($db, $run_id, $class_objects)
                ));
                $findings = array_merge($findings, $this->runPass(new TopArraysPass($db, $run_id)));
                $findings = array_merge($findings, $this->runPass(new TopStringsPass($db, $run_id)));
                $findings = array_merge($findings, $this->runPass(new NonTreeEdgePass($db, $run_id)));
                $findings = array_merge($findings, $this->runPass(new StructuralDedupPass($db, $run_id)));
            }
        }

        // Phase 3: Graph-based passes (< 500K edges, or --full-analysis)
        if ($run_phase3) {
            $substrate = GraphSubstrate::createFromDb($db, $run_id, $ffi_csr);
            $meta['scc_count'] = count($substrate->getSccProfiles());

            // Shared resolver replaces the per-edge SQL N+1 that used to
            // dominate PerPropertyMemory / Ownership / StructuralDedup /
            // PropertyScaling / NonTreeEdgePass.
            //
            // Materialising the entire tree-edge table is fastest but costs
            // memory proportional to edge count. The chosen mode (CLI flag
            // or auto-heuristic) decides between eager bulk read and bounded
            // lazy caching:
            //   - Auto:  bulk read up to ~500K edges (~50 MB worst case),
            //            lazy beyond that.
            //   - Eager: always bulk read.
            //   - Lazy:  never bulk read; per-edge prepared statement with a
            //            bounded shared cache, NonTreeEdgePass keeps its
            //            local sweep.
            $link_resolver = new LinkNameResolver($db, $run_id);
            $eager = match ($link_cache_mode) {
                LinkCacheMode::Eager => true,
                LinkCacheMode::Lazy => false,
                LinkCacheMode::Auto => $edge_count > 0 && $edge_count <= 500_000,
            };
            if ($eager) {
                $link_resolver->loadAll();
            }

            $findings = array_merge($findings, $this->runPass(
                new CycleClusterPass($substrate, $db, $run_id, $link_resolver)
            ));
            $findings = array_merge($findings, $this->runPass(
                new PropertyScalingPass($db, $run_id, $class_objects, $substrate, $link_resolver)
            ));
            $findings = array_merge($findings, $this->runPass(
                new PerPropertyMemoryPass($substrate, $db, $run_id, $link_resolver)
            ));
            $findings = array_merge($findings, $this->runPass(
                new OwnershipPatternPass($substrate, $db, $run_id, $link_resolver)
            ));
            $findings = array_merge($findings, $this->runPass(new TopArraysPass($db, $run_id, $substrate)));
            $findings = array_merge($findings, $this->runPass(new TopStringsPass($db, $run_id, $substrate)));
            $findings = array_merge($findings, $this->runPass(
                new NonTreeEdgePass($db, $run_id, $substrate, $link_resolver)
            ));
            $findings = array_merge($findings, $this->runPass(
                new StructuralDedupPass($db, $run_id, $substrate, $link_resolver)
            ));
            $findings = array_merge($findings, $this->runPass(new DrillDownPass($substrate, $db, $run_id)));
            $findings = array_merge($findings, $this->runPass(
                new ChokePointPass($substrate, $db, $run_id, $heap_usage)
            ));
            $findings = array_merge($findings, $this->runPass(new BlameAllocationPass($substrate, $db, $run_id)));
            $findings = array_merge($findings, $this->runPass(new RetainedSizeConfidencePass($substrate)));
            $findings = array_merge($findings, $this->runPass(new GcPendingPass($substrate, $db, $run_id)));
        }

        $findings = $this->deduplicateFindings($findings);
        $this->sortFindings($findings);

        return new ReportResult($meta, $findings);
    }

    /**
     * Post-process findings to reduce redundancy.
     * @param list<Finding> $findings
     * @return list<Finding>
     */
    private function deduplicateFindings(array $findings): array
    {
        $has_cycles = false;
        $has_property_scaling = false;
        foreach ($findings as $f) {
            if ($f->kind === 'cycle_cluster' || $f->kind === 'micro_cycle') {
                $has_cycles = true;
            }
            if ($f->kind === 'property_scaling') {
                $has_property_scaling = true;
            }
        }

        $fanin_count = 0;
        $findings = array_values(array_filter(
            $findings,
            function (Finding $f) use (
                $has_cycles,
                $has_property_scaling,
                &$fanin_count,
            ): bool {
                // Limit shared_fanin when cycles exist
                if ($has_cycles && $f->kind === 'shared_fanin') {
                    return ++$fanin_count <= 3;
                }
                // Suppress shared_singleton when PropertyScaling covers it
                if ($has_property_scaling && $f->kind === 'shared_singleton') {
                    return false;
                }
                return true;
            },
        ));

        return $findings;
    }

    /**
     * Sort findings by severity (high first), then impact_bytes descending.
     * @param list<Finding> $findings
     */
    private function sortFindings(array &$findings): void
    {
        $order = [
            'high' => 0,
            'warning' => 1,
            'medium' => 2,
            'low' => 3,
            'info' => 4,
        ];
        usort($findings, function (Finding $a, Finding $b) use ($order): int {
            $sa = $order[$a->severity->value] ?? 5;
            $sb = $order[$b->severity->value] ?? 5;
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }
            return $b->impact_bytes <=> $a->impact_bytes;
        });
    }

    /**
     * Run a pass safely, returning its findings or empty on error.
     * @return list<Finding>
     */
    private function runPass(PassInterface $pass): array
    {
        try {
            return $pass->analyze();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Backfill any indexes the report passes rely on, so databases
     * captured by an older Reli build still benefit on the very next
     * report run. CREATE INDEX IF NOT EXISTS is a no-op once the
     * index exists; on the first run it does a single sequential
     * scan + sort, paid back many times over by the queries that
     * follow. Failures are tolerated (read-only DBs, etc.) and just
     * leave the report on the unindexed path.
     */
    private function ensureReportIndexes(\PDO $db): void
    {
        try {
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_context_edges_run_tree_strength_link'
                . ' ON context_edges(run_id, is_tree, strength, link_name)'
            );
            // Required by TopStringsPass top-N scan to avoid sorting tens of
            // millions of ZendStringMemoryLocation rows on huge dumps.
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_context_node_locations_run_type_size'
                . ' ON context_node_locations(run_id, location_type, size)'
            );
        } catch (\PDOException) {
            // Read-only DB, missing privileges, etc. — best effort.
        }
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

        // Capture timestamp
        $stmt = $db->query(
            "SELECT created_at FROM runs WHERE run_id = {$run_id}"
        );
        $meta['captured_at'] = $stmt->fetchColumn() ?: null;

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
