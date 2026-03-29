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

use Reli\Inspector\Output\MemoryOutput\PdoDriver\PdoDriverInterface;
use Reli\Inspector\Output\MemoryOutput\PdoDriver\SqliteDriver;
use Reli\Inspector\Settings\MemoryProfilerSettings\MemoryLimitErrorDetails;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\ContextAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\PdoContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\SingleLinkContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\MemoryLocations;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ReferenceContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionBoundaries;

/**
 * Parallelizes the entire collect + analyze + write pipeline by
 * forking workers that each write to a private temporary SQLite file.
 *
 * Architecture:
 *   Main process: prepare() → fork workers → wait → ATTACH+merge temp DBs
 *   Worker N:     collect branches → ContextAnalyzer → PdoContextTreeSink → temp.db
 *
 * No pipes, no serialize — each worker runs the full pipeline
 * independently and writes directly to its own SQLite file.  The
 * parent merges all temp files into the main DB via ATTACH + INSERT SELECT
 * after all workers complete.
 *
 * Fork happens before collectAll() builds the large heap, so
 * copy-on-write pressure is minimal (dump data is read-only strings).
 */
final class ParallelCollectAnalyzer
{
    private const NODE_ID_RANGE_PER_WORKER = 100_000_000;

    public static function isAvailable(): bool
    {
        return function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid')
            && function_exists('pcntl_wifexited')
            && function_exists('pcntl_wexitstatus');
    }

    /**
     * Branches that are collected sequentially BEFORE fork.
     *
     * These build a shared MemoryLocations base that workers inherit
     * via CoW, preventing massive duplication of interned strings.
     *
     * @return list<array{string, \Closure}>
     */
    private static function preforkBranches(
        CollectionPreparation $prep,
    ): array {
        $d = $prep->dereferencer;
        $z = $prep->zend_type_reader;
        $cg = $prep->cg;
        $eg = $prep->eg;
        $map_ptr_base = $cg->map_ptr_base;

        return [
            ['included_files', function (MemoryLocationsCollector $c, MemoryLocations $ml, ContextPools $cp) use ($eg, $d) {
                return $c->collectIncludedFiles($eg->included_files, $d, $ml, $cp);
            }],
            ['interned_strings', function (MemoryLocationsCollector $c, MemoryLocations $ml, ContextPools $cp) use ($cg, $map_ptr_base, $d, $z) {
                return $c->collectInternedStrings($cg->interned_strings, $map_ptr_base, $d, $z, $ml, $cp);
            }],
            ['objects_store', function (MemoryLocationsCollector $c, MemoryLocations $ml, ContextPools $cp) use ($eg, $map_ptr_base, $d, $z) {
                return $c->collectObjectsStore($eg->objects_store, $map_ptr_base, $d, $z, $ml, $cp, null);
            }],
        ];
    }

    /**
     * Branches that are collected in parallel by forked workers.
     *
     * @return list<array{string, \Closure, bool}>  [name, callable, needs_prefork_memo]
     */
    private static function parallelBranches(
        CollectionPreparation $prep,
        ?MemoryLimitErrorDetails $memory_limit_error_details,
    ): array {
        $d = $prep->dereferencer;
        $z = $prep->zend_type_reader;
        $eg = $prep->eg;
        $cg = $prep->cg;
        $map_ptr_base = $cg->map_ptr_base;

        return [
            // objects_store: must NOT receive prefork_memo (needs to traverse properties)
            ['objects_store', function (MemoryLocationsCollector $c, MemoryLocations $ml, ContextPools $cp) use ($eg, $map_ptr_base, $d, $z, $memory_limit_error_details) {
                return $c->collectObjectsStore($eg->objects_store, $map_ptr_base, $d, $z, $ml, $cp, $memory_limit_error_details);
            }, false],
            // Other branches: receive prefork_memo so objects are reference-only
            ['global_variables', function (MemoryLocationsCollector $c, MemoryLocations $ml, ContextPools $cp) use ($eg, $map_ptr_base, $d, $z, $memory_limit_error_details) {
                return $c->collectGlobalVariables($eg->symbol_table, $map_ptr_base, $d, $z, $ml, $cp, $memory_limit_error_details);
            }, true],
            ['call_frames', function (MemoryLocationsCollector $c, MemoryLocations $ml, ContextPools $cp) use ($eg, $map_ptr_base, $d, $z, $memory_limit_error_details) {
                return $c->collectCallFrames($eg, $map_ptr_base, $d, $z, $ml, $cp, $memory_limit_error_details);
            }, true],
            ['function_table', function (MemoryLocationsCollector $c, MemoryLocations $ml, ContextPools $cp) use ($prep, $map_ptr_base, $d, $z, $memory_limit_error_details) {
                return $c->collectFunctionTable($prep->function_table, $map_ptr_base, $d, $z, $ml, $cp, $memory_limit_error_details);
            }, true],
            ['class_table', function (MemoryLocationsCollector $c, MemoryLocations $ml, ContextPools $cp) use ($prep, $map_ptr_base, $d, $z, $memory_limit_error_details) {
                return $c->collectClassTable($prep->class_table, $map_ptr_base, $d, $z, $ml, $cp, $memory_limit_error_details);
            }, true],
            ['global_constants', function (MemoryLocationsCollector $c, MemoryLocations $ml, ContextPools $cp) use ($prep, $map_ptr_base, $d, $z, $memory_limit_error_details) {
                return $c->collectGlobalConstants($prep->zend_constants, $map_ptr_base, $d, $z, $ml, $cp, $memory_limit_error_details);
            }, true],
        ];
    }

    /**
     * Run the parallel collect+analyze+write pipeline.
     *
     * Returns the list of temp DB paths for the caller to merge.
     *
     * @return array<int, string>  temp SQLite file paths (caller must merge and clean up)
     * @throws \RuntimeException if any worker fails
     */
    public function run(
        MemoryLocationsCollector $collector,
        CollectionPreparation $prep,
        PdoDriverInterface $driver,
        int $run_id,
        ?RegionBoundaries $region_boundaries,
        ?MemoryLimitErrorDetails $memory_limit_error_details = null,
    ): array {
        // ---- Phase 1: sequential pre-fork collection ----
        // Collect interned_strings and included_files into a shared
        // MemoryLocations+ContextPools.  Workers inherit these via CoW,
        // so strings already seen won't be re-traversed.
        $shared_ml = new MemoryLocations();
        foreach ($prep->huge_list_memory_locations->memory_locations as $loc) {
            $shared_ml->add($loc);
        }
        $shared_cp = ContextPools::createDefault();

        $prefork = self::preforkBranches($prep);

        // First temp DB: pre-fork branches (written by main process).
        $tmp_dir = sys_get_temp_dir();
        $pid_prefix = getmypid();
        $prefork_temp_path = "{$tmp_dir}/reli_par_{$pid_prefix}_prefork.db";

        /** @var array<string, ReferenceContext> $prefork_contexts */
        $prefork_contexts = [];
        foreach ($prefork as [$branch_name, $collect_fn]) {
            /** @var ReferenceContext $ctx */
            $ctx = $collect_fn($collector, $shared_ml, $shared_cp);
            $prefork_contexts[$branch_name] = $ctx;
        }

        // Write pre-fork branches to a temp DB and capture the memo.
        /** @var \WeakMap<ReferenceContext, int> $prefork_memo */
        $prefork_memo = new \WeakMap();
        $this->writeBranchesToTempDb(
            $prefork_temp_path,
            $prefork_contexts,
            $driver,
            $run_id,
            0, // node_id offset for pre-fork branches
            $region_boundaries,
            $prefork_memo,
        );
        // Free the context objects — they're in the DB now.
        // But keep the memo alive: workers need it (via CoW) to
        // recognize already-emitted contexts.
        unset($prefork_contexts);

        // ---- Phase 2: parallel worker collection ----
        $parallel_branches = self::parallelBranches($prep, $memory_limit_error_details);

        /** @var array<int, string> $temp_paths */
        $temp_paths = [$prefork_temp_path];
        foreach ($parallel_branches as $index => $_) {
            $temp_paths[$index + 1] = "{$tmp_dir}/reli_par_{$pid_prefix}_{$index}.db";
        }

        /** @var array<int, int> $child_pids */
        $child_pids = [];

        foreach ($parallel_branches as $index => [$branch_name, $collect_fn]) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->waitForChildren($child_pids);
                $this->cleanupTempFiles($temp_paths);
                throw new \RuntimeException('pcntl_fork() failed');
            }
            if ($pid === 0) {
                // ---- Worker process ----
                try {
                    $this->runWorker(
                        $collector,
                        $shared_ml,
                        $shared_cp,
                        $prefork_memo,
                        $driver,
                        $run_id,
                        $branch_name,
                        $collect_fn,
                        $index + 1, // offset: 0 = prefork, 1+ = workers
                        $region_boundaries,
                        $temp_paths[$index + 1],
                    );
                } catch (\Throwable $e) {
                    fwrite(STDERR, "ParallelCollectAnalyzer worker {$index} ({$branch_name}) error: {$e->getMessage()}\n");
                    // @codeCoverageIgnoreStart
                    exit(1);
                    // @codeCoverageIgnoreEnd
                }
                // @codeCoverageIgnoreStart
                exit(0);
                // @codeCoverageIgnoreEnd
            }
            $child_pids[$index] = $pid;
        }

        try {
            $this->waitForChildren($child_pids);
        } catch (\Throwable $e) {
            $this->cleanupTempFiles($temp_paths);
            throw $e;
        }

        return $temp_paths;
    }

    /**
     * Write collected branch contexts to a temp SQLite file.
     *
     * @param array<string, ReferenceContext> $branch_contexts  name => context
     * @param \WeakMap<ReferenceContext, int>|null $memo  if provided, populated with context → node_id mappings
     */
    private function writeBranchesToTempDb(
        string $temp_path,
        array $branch_contexts,
        PdoDriverInterface $driver,
        int $run_id,
        int $node_id_offset,
        ?RegionBoundaries $region_boundaries,
        ?\WeakMap $memo = null,
    ): void {
        $db = new \PDO('sqlite:' . $temp_path);
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode=OFF');
        $db->exec('PRAGMA synchronous=OFF');
        $db->exec('PRAGMA cache_size=-65536');
        $db->exec('PRAGMA temp_store=MEMORY');

        $this->createTempTables($db);

        $db->beginTransaction();
        $sink = new PdoContextTreeSink($db, $driver, $run_id, $region_boundaries);
        $analyzer = new ContextAnalyzer($node_id_offset * self::NODE_ID_RANGE_PER_WORKER);

        foreach ($branch_contexts as $branch_name => $branch_context) {
            $wrapper = new SingleLinkContext($branch_name, $branch_context);
            $analyzer->analyze($wrapper, $sink, null, $memo);
        }
        $sink->flush();
        $db->commit();
        unset($sink, $db);
    }

    /**
     * @param \WeakMap<ReferenceContext, int> $prefork_memo
     */
    private function runWorker(
        MemoryLocationsCollector $collector,
        MemoryLocations $shared_ml,
        ContextPools $shared_cp,
        \WeakMap $prefork_memo,
        PdoDriverInterface $driver,
        int $run_id,
        string $branch_name,
        \Closure $collect_fn,
        int $index,
        ?RegionBoundaries $region_boundaries,
        string $temp_path,
    ): void {
        // Clone the shared memory_locations so this worker's additions
        // don't affect other workers (CoW: the shared data is read-only).
        // The shared context_pools already contain interned string contexts
        // from the pre-fork phase.  The prefork_memo maps those contexts
        // to their node_ids so ContextAnalyzer emits references, not nodes.
        $ml = new MemoryLocations($shared_ml->memory_locations);
        $cp = $shared_cp;

        /** @var ReferenceContext $branch_context */
        $branch_context = $collect_fn($collector, $ml, $cp);

        // Write to private temp SQLite — zero contention.
        $db = new \PDO('sqlite:' . $temp_path);
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode=OFF');
        $db->exec('PRAGMA synchronous=OFF');
        $db->exec('PRAGMA cache_size=-65536');
        $db->exec('PRAGMA temp_store=MEMORY');

        $this->createTempTables($db);

        $db->beginTransaction();

        $sink = new PdoContextTreeSink($db, $driver, $run_id, $region_boundaries);
        $analyzer = new ContextAnalyzer($index * self::NODE_ID_RANGE_PER_WORKER);
        $wrapper = new SingleLinkContext($branch_name, $branch_context);

        // Pass the pre-fork memo so already-emitted contexts are
        // recognised and emitted as references rather than full nodes.
        $analyzer->analyze($wrapper, $sink, null, $prefork_memo);
        $sink->flush();

        $db->commit();
        unset($sink, $db);
    }

    private function createTempTables(\PDO $db): void
    {
        $db->exec('
            CREATE TABLE context_nodes (
                run_id INTEGER NOT NULL,
                node_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                PRIMARY KEY (run_id, node_id)
            )
        ');
        $db->exec('
            CREATE TABLE context_edges (
                run_id INTEGER NOT NULL,
                parent_node_id INTEGER,
                child_node_id INTEGER NOT NULL,
                link_name TEXT NOT NULL,
                is_tree INTEGER NOT NULL
            )
        ');
        $db->exec('
            CREATE TABLE context_node_locations (
                id INTEGER PRIMARY KEY,
                run_id INTEGER NOT NULL,
                node_id INTEGER NOT NULL,
                address BIGINT,
                size BIGINT,
                location_type TEXT NOT NULL,
                class_name TEXT,
                string_value TEXT,
                refcount BIGINT,
                type_info BIGINT,
                region TEXT
            )
        ');
        $db->exec('
            CREATE TABLE context_node_attributes (
                id INTEGER PRIMARY KEY,
                run_id INTEGER NOT NULL,
                node_id INTEGER NOT NULL,
                "key" TEXT NOT NULL,
                "value" TEXT
            )
        ');
    }

    /**
     * Merge temp files into the main DB via ATTACH + INSERT SELECT.
     *
     * The caller MUST NOT have any open DB connection to main_path
     * at the time workers were forked.
     *
     * @param array<int, string> $temp_paths
     */
    public static function mergeInto(\PDO $main_db, array $temp_paths): void
    {
        $aliases = [];
        foreach ($temp_paths as $index => $path) {
            if (!file_exists($path)) {
                continue;
            }
            $alias = "tmp{$index}";
            $main_db->exec("ATTACH DATABASE '{$path}' AS {$alias}");
            $aliases[] = $alias;
        }

        $main_db->beginTransaction();
        foreach ($aliases as $alias) {
            $main_db->exec("
                INSERT OR IGNORE INTO context_nodes (run_id, node_id, type)
                SELECT run_id, node_id, type FROM {$alias}.context_nodes
            ");
            $main_db->exec("
                INSERT INTO context_edges (run_id, parent_node_id, child_node_id, link_name, is_tree)
                SELECT run_id, parent_node_id, child_node_id, link_name, is_tree FROM {$alias}.context_edges
            ");
            $main_db->exec("
                INSERT INTO context_node_locations
                    (run_id, node_id, address, size, location_type, class_name,
                     string_value, refcount, type_info, region)
                SELECT run_id, node_id, address, size, location_type, class_name,
                       string_value, refcount, type_info, region
                FROM {$alias}.context_node_locations
            ");
            $main_db->exec("
                INSERT INTO context_node_attributes (run_id, node_id, \"key\", \"value\")
                SELECT run_id, node_id, \"key\", \"value\"
                FROM {$alias}.context_node_attributes
            ");
        }
        $main_db->commit();

        foreach ($aliases as $alias) {
            $main_db->exec("DETACH DATABASE {$alias}");
        }
    }

    /**
     * @param array<int, string> $temp_paths
     */
    public static function cleanupTempFiles(array $temp_paths): void
    {
        foreach ($temp_paths as $path) {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    /**
     * @param array<int, int> $child_pids
     * @throws \RuntimeException
     */
    private function waitForChildren(array $child_pids): void
    {
        $errors = [];
        foreach ($child_pids as $index => $pid) {
            pcntl_waitpid($pid, $status);
            if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                $errors[] = "worker {$index} (pid {$pid}) exited with status {$status}";
            }
        }
        if ($errors !== []) {
            throw new \RuntimeException(
                'ParallelCollectAnalyzer: worker failures: ' . implode('; ', $errors)
            );
        }
    }
}
