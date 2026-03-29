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
 * Parallel collect + analyze using ext-parallel threads with a
 * shared FFI CAS hash map for cross-thread address deduplication.
 *
 * Each thread:
 *   1. Reloads the dump file and constructs its own Collector
 *   2. Sets the SharedAddressSet for cross-thread dedup
 *   3. Collects its assigned branches
 *   4. Analyzes with address-based node IDs
 *   5. Writes to its own temp SQLite
 *
 * The main thread merges all temp SQLites via ATTACH + INSERT OR IGNORE.
 */
final class ParallelCollectAnalyzer
{
    public static function isAvailable(): bool
    {
        return class_exists(\parallel\Runtime::class)
            && SharedAddressSet::isAvailable();
    }

    /**
     * Branch assignment: [name, method_name, extra_args_keys]
     * Each branch maps to a collect* method on MemoryLocationsCollector.
     *
     * @return list<array{string, string}>
     */
    private static function branchNames(): array
    {
        return [
            ['included_files', 'light'],
            ['interned_strings', 'light'],
            ['global_variables', 'heavy'],
            ['call_frames', 'heavy'],
            ['function_table', 'heavy'],
            ['class_table', 'heavy'],
            ['global_constants', 'heavy'],
            ['objects_store', 'heavy'],
        ];
    }

    /**
     * Run parallel collect + analyze.
     *
     * @param string $dump_file      Path to the dump file
     * @param string $php_version    PHP version string
     * @param int    $eg_address     Executor globals address
     * @param int    $cg_address     Compiler globals address
     * @param string $autoload_path  Path to vendor/autoload.php
     * @param array<string, string> $path_mapping
     * @return list<string>  temp SQLite paths (caller merges and cleans up)
     */
    public function run(
        string $dump_file,
        string $php_version,
        int $eg_address,
        int $cg_address,
        string $autoload_path,
        PdoDriverInterface $driver,
        int $run_id,
        ?RegionBoundaries $region_boundaries,
        array $path_mapping = [],
    ): array {
        // Create shared address set for cross-thread dedup.
        $shared_set = SharedAddressSet::create(1 << 22); // 4M slots
        $set_addr = $shared_set->getAddr();

        $branches = self::branchNames();

        $tmp_dir = sys_get_temp_dir();
        $prefix = getmypid();
        /** @var list<string> $temp_paths */
        $temp_paths = [];
        foreach ($branches as $i => $_) {
            $temp_paths[$i] = "{$tmp_dir}/reli_par_{$prefix}_{$i}.db";
        }

        $so_path = SharedAddressSet::getSoPath();

        $futures = [];
        $runtimes = [];

        foreach ($branches as $i => [$branch_name, $weight]) {
            $runtimes[$i] = new \parallel\Runtime($autoload_path);
            $futures[$i] = $runtimes[$i]->run(
                function (
                    string $dump_file,
                    string $php_version,
                    int $eg_address,
                    int $cg_address,
                    int $set_addr,
                    string $so_path,
                    string $branch_name,
                    int $branch_index,
                    string $temp_path,
                    int $run_id,
                    array $path_mapping,
                ): string {
                    return self::workerMain(
                        $dump_file, $php_version, $eg_address, $cg_address,
                        $set_addr, $so_path, $branch_name, $branch_index,
                        $temp_path, $run_id, $path_mapping,
                    );
                },
                [
                    $dump_file, $php_version, $eg_address, $cg_address,
                    $set_addr, $so_path, $branch_name, $i,
                    $temp_paths[$i], $run_id, $path_mapping,
                ],
            );
        }

        $errors = [];
        foreach ($futures as $i => $future) {
            try {
                $result = $future->value();
                if (str_starts_with($result, 'ERROR:')) {
                    $errors[] = $result;
                }
            } catch (\Throwable $e) {
                $errors[] = "Thread {$i}: " . $e->getMessage();
            }
        }

        if ($errors !== []) {
            self::cleanupTempFiles($temp_paths);
            throw new \RuntimeException(
                "ParallelCollectAnalyzer failures:\n" . implode("\n", $errors)
            );
        }

        return $temp_paths;
    }

    /**
     * Worker entry point — runs in an ext-parallel thread.
     * All parameters are scalars (serializable via Channel).
     *
     * @param array<string, string> $path_mapping
     */
    private static function workerMain(
        string $dump_file,
        string $php_version,
        int $eg_address,
        int $cg_address,
        int $set_addr,
        string $so_path,
        string $branch_name,
        int $branch_index,
        string $temp_path,
        int $run_id,
        array $path_mapping,
    ): string {
        try {
            // Reconstruct shared address set from raw pointer.
            $shared_set = SharedAddressSet::fromAddr($set_addr, $so_path);

            // Reload dump and construct collector.
            $factory = new \Reli\Inspector\MemoryDump\MemoryDumpReaderFactory(
                new \DI\ContainerBuilder(),
            );
            $reader = $factory->createFromPath($dump_file, $path_mapping);

            $ref = new \ReflectionClass($reader);
            /** @var MemoryLocationsCollector $collector */
            $collector = $ref->getProperty('memory_locations_collector')->getValue($reader);
            $collector->setSharedAddressSet($shared_set);

            $ps = new \Reli\Lib\Process\ProcessSpecifier(
                $ref->getProperty('pid')->getValue($reader)
            );
            /** @var \Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings $tps */
            $tps = new \Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings(
                php_version: $php_version,
            );

            // Prepare (sequential per-thread: heap metadata, EG/CG).
            $prep = $collector->prepare($ps, $tps, $eg_address, $cg_address);

            // Collect the assigned branch.
            $ml = new MemoryLocations();
            foreach ($prep->huge_list_memory_locations->memory_locations as $loc) {
                $ml->add($loc);
            }
            $cp = ContextPools::createDefault();

            $branch_context = self::collectBranch(
                $branch_name, $collector, $prep, $ml, $cp,
            );

            $rb = new RegionBoundaries(
                $prep->chunk_memory_locations,
                $prep->huge_memory_locations,
                $prep->vm_stack_memory_locations,
                $prep->compiler_arena_memory_locations,
            );

            // Write to temp SQLite.
            $db = new \PDO('sqlite:' . $temp_path);
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $db->exec('PRAGMA journal_mode=OFF');
            $db->exec('PRAGMA synchronous=OFF');
            $db->exec('PRAGMA cache_size=-65536');
            $db->exec('PRAGMA temp_store=MEMORY');
            self::createTempTables($db);

            $driver = new SqliteDriver($temp_path);
            $db->beginTransaction();
            $sink = new PdoContextTreeSink($db, $driver, $run_id, $rb);
            $analyzer = new ContextAnalyzer(0, true); // address-based IDs
            $wrapper = new SingleLinkContext($branch_name, $branch_context);
            $analyzer->analyze($wrapper, $sink);
            $sink->flush();
            $db->commit();

            return "OK: {$branch_name}";
        } catch (\Throwable $e) {
            return "ERROR: {$branch_name}: {$e->getMessage()}";
        }
    }

    private static function collectBranch(
        string $branch_name,
        MemoryLocationsCollector $collector,
        CollectionPreparation $prep,
        MemoryLocations $ml,
        ContextPools $cp,
    ): ReferenceContext {
        $d = $prep->dereferencer;
        $z = $prep->zend_type_reader;
        $eg = $prep->eg;
        $cg = $prep->cg;
        $mpb = $cg->map_ptr_base;

        return match ($branch_name) {
            'included_files' => $collector->collectIncludedFiles($eg->included_files, $d, $ml, $cp),
            'interned_strings' => $collector->collectInternedStrings($cg->interned_strings, $mpb, $d, $z, $ml, $cp),
            'global_variables' => $collector->collectGlobalVariables($eg->symbol_table, $mpb, $d, $z, $ml, $cp, null),
            'call_frames' => $collector->collectCallFrames($eg, $mpb, $d, $z, $ml, $cp, null),
            'function_table' => $collector->collectFunctionTable($prep->function_table, $mpb, $d, $z, $ml, $cp, null),
            'class_table' => $collector->collectClassTable($prep->class_table, $mpb, $d, $z, $ml, $cp, null),
            'global_constants' => $collector->collectGlobalConstants($prep->zend_constants, $mpb, $d, $z, $ml, $cp, null),
            'objects_store' => $collector->collectObjectsStore($eg->objects_store, $mpb, $d, $z, $ml, $cp, null),
        };
    }

    private static function createTempTables(\PDO $db): void
    {
        $db->exec('CREATE TABLE context_nodes (run_id INTEGER NOT NULL, node_id INTEGER NOT NULL, type TEXT NOT NULL, PRIMARY KEY (run_id, node_id))');
        $db->exec('CREATE TABLE context_edges (run_id INTEGER NOT NULL, parent_node_id INTEGER, child_node_id INTEGER NOT NULL, link_name TEXT NOT NULL, is_tree INTEGER NOT NULL)');
        $db->exec('CREATE TABLE context_node_locations (id INTEGER PRIMARY KEY, run_id INTEGER NOT NULL, node_id INTEGER NOT NULL, address BIGINT, size BIGINT, location_type TEXT NOT NULL, class_name TEXT, string_value TEXT, refcount BIGINT, type_info BIGINT, region TEXT)');
        $db->exec('CREATE TABLE context_node_attributes (id INTEGER PRIMARY KEY, run_id INTEGER NOT NULL, node_id INTEGER NOT NULL, "key" TEXT NOT NULL, "value" TEXT)');
    }

    /**
     * @param list<string> $temp_paths
     */
    public static function mergeInto(\PDO $main_db, array $temp_paths): void
    {
        $aliases = [];
        foreach ($temp_paths as $i => $path) {
            if (!file_exists($path)) {
                continue;
            }
            $alias = "tmp{$i}";
            $main_db->exec("ATTACH DATABASE '{$path}' AS {$alias}");
            $aliases[] = $alias;
        }
        $main_db->beginTransaction();
        foreach ($aliases as $alias) {
            $main_db->exec("INSERT OR IGNORE INTO context_nodes (run_id, node_id, type) SELECT run_id, node_id, type FROM {$alias}.context_nodes");
            $main_db->exec("INSERT INTO context_edges (run_id, parent_node_id, child_node_id, link_name, is_tree) SELECT run_id, parent_node_id, child_node_id, link_name, is_tree FROM {$alias}.context_edges");
            $main_db->exec("INSERT INTO context_node_locations (run_id, node_id, address, size, location_type, class_name, string_value, refcount, type_info, region) SELECT run_id, node_id, address, size, location_type, class_name, string_value, refcount, type_info, region FROM {$alias}.context_node_locations");
            $main_db->exec("INSERT INTO context_node_attributes (run_id, node_id, \"key\", \"value\") SELECT run_id, node_id, \"key\", \"value\" FROM {$alias}.context_node_attributes");
        }
        $main_db->commit();
        foreach ($aliases as $alias) {
            $main_db->exec("DETACH DATABASE {$alias}");
        }
    }

    /**
     * @param list<string> $temp_paths
     */
    public static function cleanupTempFiles(array $temp_paths): void
    {
        foreach ($temp_paths as $path) {
            @unlink($path);
        }
    }
}
