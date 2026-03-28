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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer;

use Reli\Inspector\Output\MemoryOutput\PdoDriver\PdoDriverInterface;
use Reli\Inspector\Output\MemoryOutput\PdoDriver\SqliteDriver;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ReferenceContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionBoundaries;

/**
 * Parallelizes the context-graph traversal by forking one child process
 * per top-level branch of the ReferenceContext graph.
 *
 * Each child writes its subtree into a private temporary SQLite file
 * with maximum throughput (journal_mode=OFF, no locking contention).
 * After all children finish, the parent merges the temp files into the
 * main database via ATTACH + INSERT … SELECT.
 *
 * For non-SQLite drivers, children write directly to the shared
 * database (MySQL/PostgreSQL handle concurrent writers natively).
 *
 * Node-ID ranges are partitioned (100 000 000 IDs per branch) so that
 * children never collide.
 */
final class ParallelContextAnalyzer
{
    private const NODE_ID_RANGE_PER_BRANCH = 100_000_000;

    public static function isAvailable(): bool
    {
        return function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid')
            && function_exists('pcntl_wifexited')
            && function_exists('pcntl_wexitstatus');
    }

    /**
     * @throws \RuntimeException if any child process fails
     */
    public function analyze(
        ReferenceContext $root,
        PdoDriverInterface $driver,
        int $run_id,
        ?RegionBoundaries $region_boundaries = null,
    ): void {
        $branches = [];
        foreach ($root->getLinks() as $link_name => $linked_context) {
            /** @psalm-suppress RedundantCastGivenDocblockType -- int keys occur at runtime */
            $branches[] = [(string)$link_name, $linked_context];
        }

        if ($branches === []) {
            return;
        }

        // For a single branch, skip forking overhead.
        if (count($branches) === 1) {
            $this->processBranchDirect(
                $branches[0][0],
                $branches[0][1],
                $driver,
                $run_id,
                0,
                $region_boundaries,
            );
            return;
        }

        $use_temp_files = $driver instanceof SqliteDriver;

        /**
         * @var array<int, string> $temp_paths  index => temp file path (SQLite only)
         * Pre-computed deterministic paths so both parent and children know where to look.
         */
        $temp_paths = [];
        $child_pids = [];

        if ($use_temp_files) {
            $tmp_dir = sys_get_temp_dir();
            $pid_prefix = getmypid();
            foreach ($branches as $index => $_) {
                $temp_paths[$index] = "{$tmp_dir}/reli_par_{$pid_prefix}_{$index}.db";
            }
        }

        foreach ($branches as $index => [$link_name, $linked_context]) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->waitForChildren($child_pids);
                $this->cleanupTempFiles($temp_paths);
                throw new \RuntimeException('pcntl_fork() failed');
            }
            if ($pid === 0) {
                // Child process
                try {
                    if ($use_temp_files) {
                        $this->processBranchToTempSqlite(
                            $link_name,
                            $linked_context,
                            $driver,
                            $run_id,
                            $index * self::NODE_ID_RANGE_PER_BRANCH,
                            $region_boundaries,
                            $temp_paths[$index],
                        );
                    } else {
                        $this->processBranchDirect(
                            $link_name,
                            $linked_context,
                            $driver,
                            $run_id,
                            $index * self::NODE_ID_RANGE_PER_BRANCH,
                            $region_boundaries,
                        );
                    }
                } catch (\Throwable $e) {
                    fwrite(STDERR, "ParallelContextAnalyzer child {$index} error: {$e->getMessage()}\n");
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

            if ($use_temp_files) {
                $merge_db = $driver->createConnection();
                $driver->tuneForParallelInsert($merge_db);
                $this->mergeFromTempFiles($merge_db, $temp_paths);
                unset($merge_db);
            }
        } finally {
            $this->cleanupTempFiles($temp_paths);
        }
    }

    /**
     * Write directly to the target database (for MySQL/PostgreSQL or single-branch fallback).
     */
    private function processBranchDirect(
        string $link_name,
        ReferenceContext $linked_context,
        PdoDriverInterface $driver,
        int $run_id,
        int $start_node_id,
        ?RegionBoundaries $region_boundaries,
    ): void {
        $db = $driver->createConnection();
        $driver->tuneForParallelInsert($db);

        $db->beginTransaction();

        $sink = new PdoContextTreeSink($db, $driver, $run_id, $region_boundaries);
        $analyzer = new ContextAnalyzer($start_node_id);
        $wrapper = new SingleLinkContext($link_name, $linked_context);

        $analyzer->analyze($wrapper, $sink);
        $sink->flush();

        $db->commit();
    }

    /**
     * Write to a private temp SQLite file (zero contention, maximum throughput).
     */
    private function processBranchToTempSqlite(
        string $link_name,
        ReferenceContext $linked_context,
        PdoDriverInterface $driver,
        int $run_id,
        int $start_node_id,
        ?RegionBoundaries $region_boundaries,
        string $temp_path,
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
        $analyzer = new ContextAnalyzer($start_node_id);
        $wrapper = new SingleLinkContext($link_name, $linked_context);

        $analyzer->analyze($wrapper, $sink);
        $sink->flush();

        $db->commit();

        // Explicitly close before exit so locks are released.
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
     * ATTACH each temp file and bulk-copy rows into the main DB.
     *
     * The caller MUST close any PDO connections to the main database
     * before forking children; otherwise the inherited file descriptor
     * causes "database is locked" on ATTACH.
     *
     * @param array<int, string> $temp_paths
     */
    private function mergeFromTempFiles(\PDO $main_db, array $temp_paths): void
    {
        // ATTACH all temp databases first (outside transaction).
        $aliases = [];
        foreach ($temp_paths as $index => $path) {
            if (!file_exists($path)) {
                continue;
            }
            $alias = "tmp{$index}";
            $main_db->exec("ATTACH DATABASE '{$path}' AS {$alias}");
            $aliases[] = $alias;
        }

        // Bulk-copy inside a single transaction.
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

        // DETACH after commit.
        foreach ($aliases as $alias) {
            $main_db->exec("DETACH DATABASE {$alias}");
        }
    }

    /**
     * @param array<int, int> $child_pids  index => pid
     * @throws \RuntimeException if any child exited with non-zero status
     */
    private function waitForChildren(array $child_pids): void
    {
        $errors = [];
        foreach ($child_pids as $index => $pid) {
            pcntl_waitpid($pid, $status);
            if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                $errors[] = "branch {$index} (pid {$pid}) exited with status {$status}";
            }
        }
        if ($errors !== []) {
            throw new \RuntimeException(
                'ParallelContextAnalyzer: child process failures: ' . implode('; ', $errors)
            );
        }
    }

    /**
     * @param array<int, string> $temp_paths
     */
    private function cleanupTempFiles(array $temp_paths): void
    {
        foreach ($temp_paths as $path) {
            @unlink($path);
            // WAL/SHM sidecar files
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }
}
