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
     * @param int $bulk_fetch_chunk rows per chunked fetchAll inside the
     *   substrate loaders. 0 disables chunking (single fetchAll, max
     *   memory). Plumbed through to {@see GraphSubstrate::createFromDb}.
     * @param int $worker_count number of parallel workers for Phase 3
     *   passes. 1 = sequential (default, current behavior). >1 = fork
     *   worker_count children, each running a subset of passes in
     *   parallel. Requires `$db_path` to be set so workers can open
     *   fresh PDO connections after fork.
     * @param string|null $db_path Path to the SQLite analysis DB.
     *   Required when worker_count > 1 (workers reopen the DB rather
     *   than share the parent's PDO across fork). Ignored in
     *   sequential mode.
     */
    public function generateFromDb(
        \PDO $db,
        int $run_id = 1,
        bool $full_analysis = false,
        ?bool $ffi_csr = null,
        LinkCacheMode $link_cache_mode = LinkCacheMode::Auto,
        int $bulk_fetch_chunk = 200000,
        int $worker_count = 1,
        ?string $db_path = null,
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
            // CallStackPass has a substrate-backed analyzeWithSubstrate
            // path that's an order of magnitude cheaper than its
            // 4-JOIN SQL fallback. Defer it to Phase 3 when the
            // substrate is going to be built; the SQL fallback only
            // runs on small graphs that skip Phase 3 entirely.
            if (!$run_phase3) {
                $findings = array_merge($findings, $this->runPass(new CallStackPass($db, $run_id)));
            }
            // DynamicProperties / PropertyScaling / TopArrays / TopStrings /
            // NonTreeEdge / StructuralDedup are all "deferred to Phase 3 if
            // graph is available" — when the substrate isn't built we run
            // their SQL fallbacks here, otherwise the Phase 3 block below
            // takes over and feeds them the substrate.
            if (!$run_phase3) {
                $findings = array_merge($findings, $this->runPass(new DynamicPropertiesPass($db, $run_id)));
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
            $substrate = GraphSubstrate::createFromDb($db, $run_id, $ffi_csr, $bulk_fetch_chunk);
            $meta['scc_count'] = count($substrate->getSccProfiles());

            // CallStackPass deferred from Phase 2: now that the
            // substrate is built, run it through the in-memory
            // node-type + tree-edge indexes instead of the 4-JOIN SQL.
            $findings = array_merge($findings, $this->runPass(
                new CallStackPass($db, $run_id, $substrate)
            ));

            // Shared resolver replaces the per-edge SQL N+1 that used to
            // dominate PerPropertyMemory / Ownership / StructuralDedup /
            // PropertyScaling / NonTreeEdgePass.
            //
            // The substrate now carries a tree-edge link_name index built
            // for free during loadEdgesFfi/loadEdges, so the resolver
            // delegates to it and answers every lookup in O(1) memory —
            // the legacy eager/lazy paths are kept as fallbacks for code
            // paths that don't have a substrate (Phase 2 SQL passes).
            $link_resolver = new LinkNameResolver(
                db: $db,
                run_id: $run_id,
                substrate: $substrate,
            );
            $eager = match ($link_cache_mode) {
                LinkCacheMode::Eager => true,
                LinkCacheMode::Lazy => false,
                LinkCacheMode::Auto => $edge_count > 0 && $edge_count <= 500_000,
            };
            // Substrate-backed lookups are always O(1) regardless, but
            // when somebody runs --link-cache=eager explicitly we still
            // honour it for the no-substrate code paths inside the
            // resolver itself.
            if ($eager && !$substrate->hasTreeLinkIndex()) {
                $link_resolver->loadAll();
            }

            // Phase 3 passes are independent and read-only over the
            // substrate + db. Build factory closures for each, hand
            // them to the ParallelPassRunner which either runs them
            // sequentially (worker_count <= 1) or forks workers that
            // share the substrate via copy-on-write.
            //
            // Each factory opens its OWN PDO inside the closure so
            // forked workers don't end up sharing the parent's file
            // descriptor (which would corrupt SQLite). In sequential
            // mode the extra PDO open is a few ms per pass, dwarfed
            // by the actual pass work.
            $open_db = function () use ($db_path): \PDO {
                assert($db_path !== null);
                $child_db = new \PDO("sqlite:{$db_path}");
                $child_db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $child_db->exec('PRAGMA journal_mode = WAL');
                $child_db->exec('PRAGMA mmap_size = 268435456');
                return $child_db;
            };
            // Resolve "fresh PDO per factory" only when we need it
            // (parallel + db_path set). For sequential-with-parent-
            // PDO we reuse $db directly and save the reopen cost.
            $workers_want_fresh_db = $worker_count > 1 && $db_path !== null;
            $db_factory = $workers_want_fresh_db
                ? $open_db
                : fn () => $db;
            $resolver_factory = function () use ($db_factory, $run_id, $substrate): LinkNameResolver {
                return new LinkNameResolver(
                    db: $db_factory(),
                    run_id: $run_id,
                    substrate: $substrate,
                );
            };

            /** @var array<string, callable(): list<Finding>> $pass_factories */
            $pass_factories = [];
            $pass_factories['DynamicPropertiesPass'] = fn (): array => $this->runPass(
                new DynamicPropertiesPass($db_factory(), $run_id, $substrate)
            );
            $pass_factories['CycleClusterPass'] = fn (): array => $this->runPass(
                new CycleClusterPass($substrate, $db_factory(), $run_id, $resolver_factory())
            );
            $pass_factories['PropertyScalingPass'] = fn (): array => $this->runPass(
                new PropertyScalingPass(
                    $db_factory(),
                    $run_id,
                    $class_objects,
                    $substrate,
                    $resolver_factory(),
                )
            );
            $pass_factories['PerPropertyMemoryPass'] = fn (): array => $this->runPass(
                new PerPropertyMemoryPass($substrate, $db_factory(), $run_id, $resolver_factory())
            );
            $pass_factories['OwnershipPatternPass'] = fn (): array => $this->runPass(
                new OwnershipPatternPass($substrate, $db_factory(), $run_id, $resolver_factory())
            );
            $pass_factories['TopArraysPass'] = fn (): array => $this->runPass(
                new TopArraysPass($db_factory(), $run_id, $substrate)
            );
            $pass_factories['TopStringsPass'] = fn (): array => $this->runPass(
                new TopStringsPass($db_factory(), $run_id, $substrate)
            );
            $pass_factories['NonTreeEdgePass'] = fn (): array => $this->runPass(
                new NonTreeEdgePass($db_factory(), $run_id, $substrate, $resolver_factory())
            );
            $pass_factories['StructuralDedupPass'] = fn (): array => $this->runPass(
                new StructuralDedupPass($db_factory(), $run_id, $substrate, $resolver_factory())
            );
            $pass_factories['DrillDownPass'] = fn (): array => $this->runPass(
                new DrillDownPass($substrate, $db_factory(), $run_id)
            );
            $pass_factories['ChokePointPass'] = fn (): array => $this->runPass(
                new ChokePointPass($substrate, $db_factory(), $run_id, $heap_usage)
            );
            $pass_factories['BlameAllocationPass'] = fn (): array => $this->runPass(
                new BlameAllocationPass($substrate, $db_factory(), $run_id)
            );
            $pass_factories['RetainedSizeConfidencePass'] = fn (): array => $this->runPass(
                new RetainedSizeConfidencePass($substrate)
            );
            $pass_factories['GcPendingPass'] = fn (): array => $this->runPass(
                new GcPendingPass($substrate, $db_factory(), $run_id)
            );

            $runner = new ParallelPassRunner($workers_want_fresh_db ? $worker_count : 1);
            $findings = array_merge($findings, $runner->run($pass_factories));
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
            // The 4-col version was the original NonTreeEdgePass index;
            // it's now superseded by a 6-col covering variant that lets
            // the shared_rows aggregation finish without ever leaving
            // the index. Drop the old one so old captures don't carry
            // both indexes side by side.
            $db->exec('DROP INDEX IF EXISTS idx_context_edges_run_tree_strength_link');
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_context_edges_run_tree_strength_link_child_parent'
                . ' ON context_edges(run_id, is_tree, strength, link_name, child_node_id, parent_node_id)'
            );
            // Required by TopStringsPass top-N scan to avoid sorting tens of
            // millions of ZendStringMemoryLocation rows on huge dumps.
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_context_node_locations_run_type_size'
                . ' ON context_node_locations(run_id, location_type, size)'
            );
            // NodeLabeler::ensureLoaded filters by (run_id, key) when
            // it pulls function_name / lineno attrs for CallFrame
            // labels. Without this index that lookup falls back to a
            // run_id-only index range scan that walks every attribute
            // row in the run, which on big captures showed up as ~5%
            // of total report runtime in PDOStatement::fetchAll.
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_context_node_attributes_run_key_node'
                . ' ON context_node_attributes(run_id, key, node_id)'
            );
            // Covering index on context_nodes for the substrate's
            // chunked loadNodeTypesFfi: paginating by node_id needs
            // an index that includes `type` so SQLite can answer the
            // query from the index alone, without a per-row heap
            // lookup.
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_context_nodes_run_node_type'
                . ' ON context_nodes(run_id, node_id, type)'
            );
            // (run_id, id) on context_edges for the chunked
            // loadEdgesFfi. Requires the `id INTEGER PRIMARY KEY`
            // column added by the matching schema change in
            // PdoMemoryOutput; dumps captured with older schemas
            // need to be re-analysed before the report can run.
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_context_edges_run_id'
                . ' ON context_edges(run_id, id)'
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
