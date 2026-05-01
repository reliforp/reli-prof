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

use PhpCast\Cast;
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Format;
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Reader as BinaryReader;
use Reli\Inspector\Output\MemoryOutput\PdoDriver\PdoDriverInterface;
use Reli\Inspector\Output\MemoryOutput\PdoDriver\SqliteDriver;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\ContextAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\PdoContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionBoundaries;

final class PdoMemoryOutput implements MemoryOutputInterface
{
    public function __construct(
        private PdoDriverInterface $driver,
        private ?RegionBoundaries $region_boundaries = null,
    ) {
    }

    #[\Override]
    public function output(MemoryAnalysisResult $result): void
    {
        if ($result->pre_populated_rmem_path !== null) {
            $this->ingestFromRmem($result->pre_populated_rmem_path, $result->summary);
            return;
        }

        if ($result->pre_populated_db !== null && $result->pre_populated_run_id !== null) {
            // Context tree was already streamed to this DB during collection.
            // Just copy the data to the target DB.
            $target_db = $this->driver->createConnection();
            $this->driver->tuneForBulkInsert($target_db);
            $this->createTables($target_db);

            $target_db->beginTransaction();
            try {
                $this->copyFromPrePopulatedDb(
                    $result->pre_populated_db,
                    $result->pre_populated_run_id,
                    $target_db,
                );
                $target_db->commit();
            } catch (\Throwable $e) {
                $target_db->rollBack();
                throw $e;
            }

            $this->driver->afterBulkInsert($target_db);
            $this->createIndexes($target_db);
            $this->createViews($target_db);
            return;
        }

        $db = $this->driver->createConnection();
        $this->driver->tuneForBulkInsert($db);

        $this->createTables($db);

        $db->beginTransaction();
        try {
            $run_id = $this->insertRun($db);
            $this->insertSummary($db, $run_id, $result->summary);

            if ($result->context !== null) {
                $sink = new PdoContextTreeSink($db, $this->driver, $run_id, $this->region_boundaries);
                $analyzer = new ContextAnalyzer();
                $analyzer->analyze($result->context, $sink);
                $sink->flush();
            }

            $this->insertLocationTypesSummaryFromDb($db, $run_id);
            $this->insertClassObjectsSummaryFromDb($db, $run_id);
            $this->computeCanonicalNodeIds($db, $run_id);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        $this->driver->afterBulkInsert($db);
        $this->createIndexes($db);
        $this->createViews($db);
    }

    /**
     * Create sink for streaming context tree directly during collection.
     * The returned sink, run_id, and PDO connection can be passed to
     * MemoryLocationsCollector::collectAll() for interleaved emission.
     *
     * @return array{PdoContextTreeSink, int, \PDO}
     */
    public function createStreamingSink(): array
    {
        $db = $this->driver->createConnection();
        $this->driver->tuneForBulkInsert($db);
        $this->createTables($db);
        $db->beginTransaction();
        $run_id = $this->insertRun($db);

        $sink = new PdoContextTreeSink($db, $this->driver, $run_id, $this->region_boundaries);
        return [$sink, $run_id, $db];
    }

    /**
     * Finalize a streaming session started by createStreamingSink().
     *
     * @param array<int, array<string, mixed>> $summary
     */
    public function finalizeStreaming(\PDO $db, int $run_id, PdoContextTreeSink $sink, array $summary): void
    {
        $sink->flush();
        $this->insertSummary($db, $run_id, $summary);
        $this->insertLocationTypesSummaryFromDb($db, $run_id);
        $this->insertClassObjectsSummaryFromDb($db, $run_id);
        $this->computeCanonicalNodeIds($db, $run_id);
        $db->commit();
        $this->driver->afterBulkInsert($db);
        $this->createIndexes($db);
        $this->createViews($db);
    }

    /**
     * Bulk-load an .rmem intermediate into the target database.
     *
     * Schema construction, summary derivation, canonical-id resolution,
     * index build, and view creation all happen here — the rmem file is
     * a fully self-describing snapshot, so we never need to walk the
     * in-memory context tree again. Called when the caller has already
     * streamed the collection into a temp .rmem and now wants the same
     * data materialised into a SQL database.
     *
     * Picks the parallel-shard implementation when available
     * (SQLite target + pcntl extension) and falls back to the
     * single-process implementation otherwise.
     *
     * @param array<int, array<string, mixed>> $summary
     */
    public function ingestFromRmem(string $rmem_path, array $summary): void
    {
        if (
            $this->driver instanceof SqliteDriver
            && extension_loaded('pcntl')
            && function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid')
        ) {
            $this->ingestFromRmemParallel($rmem_path, $summary);
            return;
        }
        $this->ingestFromRmemSerial($rmem_path, $summary);
    }

    /**
     * Single-process rmem -> DB bulk-load. Used as a fallback when the
     * parallel-shard implementation isn't applicable (non-SQLite
     * target, or pcntl unavailable).
     *
     * @param array<int, array<string, mixed>> $summary
     * @psalm-suppress InaccessibleMethod
     * @psalm-suppress PossiblyNullPropertyFetch
     * @psalm-suppress UndefinedPropertyFetch
     * @psalm-suppress InvalidPropertyFetch
     * @psalm-suppress MixedAssignment
     * @psalm-suppress PossiblyInvalidArrayAccess
     */
    public function ingestFromRmemSerial(string $rmem_path, array $summary): void
    {
        $reader = BinaryReader::open($rmem_path);
        $dict = $reader->getStringDict();

        $db = $this->driver->createConnection();
        $this->driver->tuneForBulkInsert($db);
        $this->createTables($db);

        $db->beginTransaction();
        try {
            $run_id = $this->insertRun($db);
            $this->insertSummary($db, $run_id, $summary);

            $this->ingestNodes($db, $reader, $run_id);
            $this->ingestEdges($db, $reader, $run_id);
            $this->ingestLocations($db, $reader, $run_id);
            $this->ingestAttributes($db, $reader, $run_id);

            $this->insertLocationTypesSummaryFromDb($db, $run_id);
            $this->insertClassObjectsSummaryFromDb($db, $run_id);
            $this->computeCanonicalNodeIds($db, $run_id);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        $this->driver->afterBulkInsert($db);
        $this->createIndexes($db);
        $this->createViews($db);
    }

    /**
     * @psalm-suppress InaccessibleMethod
     * @psalm-suppress PossiblyNullPropertyFetch
     * @psalm-suppress UndefinedPropertyFetch
     * @psalm-suppress InvalidPropertyFetch
     * @psalm-suppress MixedAssignment
     * @psalm-suppress PossiblyInvalidArrayAccess
     */
    /**
     * Parallel-shard rmem -> SQLite bulk-load.
     *
     * Forks one worker per heavy table (nodes / edges / locations /
     * attributes). Each worker reads its assigned section from rmem and
     * bulk-inserts into a temp shard SQLite — no indexes, run_id stored
     * as 0 placeholder. The main process then ATTACHes each shard and
     * copies via INSERT INTO main.t SELECT real_run_id, ... FROM s.t,
     * which lets SQLite use its sequential-rowid fast path. CREATE
     * INDEX runs once at the end on the merged main DB.
     *
     * Workers run concurrently because each writes to a different
     * file — SQLite's per-file writer lock is the reason this scales.
     *
     * @param array<int, array<string, mixed>> $summary
     * @psalm-suppress UnusedFunctionCall
     */
    public function ingestFromRmemParallel(string $rmem_path, array $summary): void
    {
        $tables = [
            'nodes' => Format::SECTION_NODES,
            'edges' => Format::SECTION_EDGES,
            'locations' => Format::SECTION_LOCATIONS,
            'attributes' => Format::SECTION_ATTRIBUTES,
        ];

        $shard_paths = [];
        foreach (array_keys($tables) as $name) {
            $base = tempnam(sys_get_temp_dir(), "reli_shard_{$name}_");
            if ($base === false) {
                $this->cleanupShards($shard_paths);
                throw new \RuntimeException('Failed to create temporary file for shard');
            }
            $shard_paths[$name] = $base . '.sqlite3';
            @unlink($base);
        }

        try {
            $pids = [];
            foreach ($tables as $name => $section) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    foreach ($pids as $running_pid) {
                        posix_kill($running_pid, SIGTERM);
                        pcntl_waitpid($running_pid, $_status);
                    }
                    throw new \RuntimeException('pcntl_fork failed');
                }
                if ($pid === 0) {
                    try {
                        $this->writeShard($rmem_path, $name, $shard_paths[$name]);
                        exit(0);
                    } catch (\Throwable $e) {
                        fwrite(STDERR, "shard {$name} worker failed: " . $e->getMessage() . "\n");
                        exit(1);
                    }
                }
                $pids[$name] = $pid;
            }

            $failed = [];
            foreach ($pids as $name => $pid) {
                pcntl_waitpid($pid, $status);
                if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                    $failed[] = $name;
                }
            }
            if ($failed !== []) {
                throw new \RuntimeException(
                    'Parallel shard workers failed: ' . implode(', ', $failed)
                );
            }

            $this->mergeShards($shard_paths, $summary);
        } finally {
            $this->cleanupShards($shard_paths);
        }
    }

    /**
     * @param array<string, string> $shard_paths name => filesystem path
     */
    private function cleanupShards(array $shard_paths): void
    {
        foreach ($shard_paths as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * Worker entry point: open the shard file, set up the per-table
     * schema, and stream the matching rmem section into it. Runs in a
     * forked child process; throws are caught by the caller and
     * surfaced as a non-zero exit status.
     */
    private function writeShard(string $rmem_path, string $table, string $shard_path): void
    {
        $reader = BinaryReader::open($rmem_path);
        $shard = new \PDO("sqlite:{$shard_path}");
        $shard->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $shard->exec('PRAGMA synchronous = OFF');
        $shard->exec('PRAGMA journal_mode = MEMORY');
        $shard->exec('PRAGMA temp_store = MEMORY');

        $this->createShardTable($shard, $table);

        $shard->beginTransaction();
        try {
            $placeholder_run_id = 0;
            switch ($table) {
                case 'nodes':
                    $this->ingestNodes($shard, $reader, $placeholder_run_id);
                    break;
                case 'edges':
                    $this->ingestEdges($shard, $reader, $placeholder_run_id);
                    break;
                case 'locations':
                    $this->ingestLocations($shard, $reader, $placeholder_run_id);
                    break;
                case 'attributes':
                    $this->ingestAttributes($shard, $reader, $placeholder_run_id);
                    break;
                default:
                    throw new \RuntimeException("unknown shard table: {$table}");
            }
            $shard->commit();
        } catch (\Throwable $e) {
            $shard->rollBack();
            throw $e;
        }
        // Explicitly close the PDO connection so SQLite's checkpoint/
        // journal cleanup runs before the worker exit()s — exit()
        // alone leaves SQLite-internal state in a way that can leave
        // the shard file in a locked state when the parent ATTACHes.
        unset($shard);
    }

    /**
     * Create only the table the worker is responsible for. Schema
     * matches the main DB's column list (so INSERT INTO main SELECT *
     * mostly works) but omits indexes, primary keys, and id surrogates
     * — those would slow the worker's inserts and we drop them on the
     * way out anyway.
     */
    private function createShardTable(\PDO $shard, string $table): void
    {
        switch ($table) {
            case 'nodes':
                $shard->exec(
                    'CREATE TABLE context_nodes ('
                    . ' run_id INTEGER NOT NULL,'
                    . ' node_id INTEGER NOT NULL,'
                    . ' type TEXT NOT NULL,'
                    . ' canonical_node_id INTEGER'
                    . ')'
                );
                return;
            case 'edges':
                $shard->exec(
                    'CREATE TABLE context_edges ('
                    . ' run_id INTEGER NOT NULL,'
                    . ' parent_node_id INTEGER,'
                    . ' child_node_id INTEGER NOT NULL,'
                    . ' link_name TEXT NOT NULL,'
                    . ' is_tree INTEGER NOT NULL,'
                    . " strength TEXT NOT NULL DEFAULT 'strong'"
                    . ')'
                );
                return;
            case 'locations':
                $shard->exec(
                    'CREATE TABLE context_node_locations ('
                    . ' run_id INTEGER NOT NULL,'
                    . ' node_id INTEGER NOT NULL,'
                    . ' address BIGINT,'
                    . ' size BIGINT,'
                    . ' location_type TEXT NOT NULL,'
                    . ' class_name TEXT,'
                    . ' string_value TEXT,'
                    . ' refcount BIGINT,'
                    . ' type_info BIGINT,'
                    . ' region TEXT,'
                    . ' bin_overhead BIGINT DEFAULT 0'
                    . ')'
                );
                return;
            case 'attributes':
                $shard->exec(
                    'CREATE TABLE context_node_attributes ('
                    . ' run_id INTEGER NOT NULL,'
                    . ' node_id INTEGER NOT NULL,'
                    . ' "key" TEXT NOT NULL,'
                    . ' "value" TEXT'
                    . ')'
                );
                return;
            default:
                throw new \RuntimeException("unknown shard table: {$table}");
        }
    }

    /**
     * Open the target DB, create the full schema, ATTACH each shard,
     * and copy its rows over while rewriting the run_id placeholder to
     * the freshly-allocated run_id. After the copy, build the derived
     * summaries, canonical-id map, indexes, and views as in the
     * single-process path.
     *
     * @param array<string, string> $shard_paths
     * @param array<int, array<string, mixed>> $summary
     */
    private function mergeShards(array $shard_paths, array $summary): void
    {
        $db = $this->driver->createConnection();
        // Skip tuneForBulkInsert — its `locking_mode = EXCLUSIVE`
        // on the main DB makes ATTACHed shards appear locked at
        // SELECT time. Use the safe subset of pragmas instead;
        // bulk-load durability tuning happens later anyway via
        // afterBulkInsert / createIndexes.
        $db->exec('PRAGMA synchronous = OFF');
        $db->exec('PRAGMA temp_store = MEMORY');
        $this->createTables($db);

        // ATTACH outside any active write transaction.
        foreach ($shard_paths as $alias => $path) {
            $db->exec("ATTACH DATABASE '{$path}' AS s_{$alias}");
        }

        $db->beginTransaction();
        try {
            $run_id = $this->insertRun($db);
            $this->insertSummary($db, $run_id, $summary);

            // Per-table copy. The SELECT rewrites run_id from the 0
            // placeholder to the real run_id allocated above. We let
            // SQLite use sequential-rowid append on the main tables;
            // CREATE INDEX runs after the transaction commits.
            $db->exec(
                "INSERT INTO context_nodes (run_id, node_id, type, canonical_node_id)"
                . " SELECT {$run_id}, node_id, type, canonical_node_id FROM s_nodes.context_nodes"
            );
            $db->exec(
                "INSERT INTO context_edges (run_id, parent_node_id, child_node_id, link_name, is_tree, strength)"
                . " SELECT {$run_id}, parent_node_id, child_node_id, link_name, is_tree, strength"
                . " FROM s_edges.context_edges"
            );
            $db->exec(
                "INSERT INTO context_node_locations"
                . " (run_id, node_id, address, size, location_type, class_name, string_value,"
                . "  refcount, type_info, region, bin_overhead)"
                . " SELECT {$run_id}, node_id, address, size, location_type, class_name, string_value,"
                . "  refcount, type_info, region, bin_overhead"
                . " FROM s_locations.context_node_locations"
            );
            $db->exec(
                "INSERT INTO context_node_attributes (run_id, node_id, \"key\", \"value\")"
                . " SELECT {$run_id}, node_id, \"key\", \"value\" FROM s_attributes.context_node_attributes"
            );

            // DETACH cannot run inside a transaction in SQLite. Skip
            // it here — the attached aliases drop automatically when
            // $db is closed at the end of this method.
            $this->insertLocationTypesSummaryFromDb($db, $run_id);
            $this->insertClassObjectsSummaryFromDb($db, $run_id);
            $this->computeCanonicalNodeIds($db, $run_id);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        $this->driver->afterBulkInsert($db);
        $this->createIndexes($db);
        $this->createViews($db);
    }

    /**
     * @psalm-suppress InaccessibleMethod
     * @psalm-suppress PossiblyNullPropertyFetch
     * @psalm-suppress UndefinedPropertyFetch
     * @psalm-suppress InvalidPropertyFetch
     * @psalm-suppress MixedAssignment
     * @psalm-suppress PossiblyInvalidArrayAccess
     */
    private function ingestNodes(\PDO $db, BinaryReader $reader, int $run_id): void
    {
        if (!$reader->hasSection(Format::SECTION_NODES)) {
            return;
        }
        $dict = $reader->getStringDict();
        $count = $reader->getSectionElementCount(Format::SECTION_NODES);
        if ($count === 0) {
            return;
        }
        $insert = $db->prepare(
            'INSERT INTO context_nodes (run_id, node_id, type, canonical_node_id) VALUES (?, ?, ?, NULL)'
        );
        $rows = $reader->castSection(Format::SECTION_NODES, 'NodeRow');
        if ($rows !== null) {
            for ($i = 0; $i < $count; $i++) {
                $type = $dict->lookup($rows[$i]->type_id) ?? '';
                $insert->execute([$run_id, $rows[$i]->node_id, $type]);
            }
            return;
        }
        $data = $reader->getSectionData(Format::SECTION_NODES);
        for ($i = 0; $i < $count; $i++) {
            $off = $i * Format::NODE_ROW_SIZE;
            $row = unpack('Vnode_id/Vcanonical_id/Vtype_id', $data, $off);
            $type = $dict->lookup((int)$row['type_id']) ?? '';
            $insert->execute([$run_id, (int)$row['node_id'], $type]);
        }
    }

    /**
     * @psalm-suppress InaccessibleMethod
     * @psalm-suppress PossiblyNullPropertyFetch
     * @psalm-suppress UndefinedPropertyFetch
     * @psalm-suppress InvalidPropertyFetch
     * @psalm-suppress MixedAssignment
     * @psalm-suppress PossiblyInvalidArrayAccess
     */
    private function ingestEdges(\PDO $db, BinaryReader $reader, int $run_id): void
    {
        if (!$reader->hasSection(Format::SECTION_EDGES)) {
            return;
        }
        $dict = $reader->getStringDict();
        $count = $reader->getSectionElementCount(Format::SECTION_EDGES);
        if ($count === 0) {
            return;
        }
        $insert = $db->prepare(
            'INSERT INTO context_edges (run_id, parent_node_id, child_node_id, link_name, is_tree, strength)'
            . ' VALUES (?, ?, ?, ?, ?, ?)'
        );
        $strengths = ['strong', 'weak', 'structural'];
        $rows = $reader->castSection(Format::SECTION_EDGES, 'EdgeRow');
        if ($rows !== null) {
            for ($i = 0; $i < $count; $i++) {
                $parent = $rows[$i]->parent_node_id;
                $insert->execute([
                    $run_id,
                    $parent === Format::NULL_STRING_ID ? null : $parent,
                    $rows[$i]->child_node_id,
                    $dict->lookup($rows[$i]->link_name_id) ?? '',
                    $rows[$i]->is_tree,
                    $strengths[$rows[$i]->strength] ?? 'strong',
                ]);
            }
            return;
        }
        $data = $reader->getSectionData(Format::SECTION_EDGES);
        for ($i = 0; $i < $count; $i++) {
            $off = $i * Format::EDGE_ROW_SIZE;
            $row = unpack('Vparent/Vchild/Vlid/Cis_tree/Cstrength', $data, $off);
            $parent = (int)$row['parent'];
            $insert->execute([
                $run_id,
                $parent === Format::NULL_STRING_ID ? null : $parent,
                (int)$row['child'],
                $dict->lookup((int)$row['lid']) ?? '',
                (int)$row['is_tree'],
                $strengths[(int)$row['strength']] ?? 'strong',
            ]);
        }
    }

    /**
     * @psalm-suppress InaccessibleMethod
     * @psalm-suppress PossiblyNullPropertyFetch
     * @psalm-suppress UndefinedPropertyFetch
     * @psalm-suppress InvalidPropertyFetch
     * @psalm-suppress MixedAssignment
     * @psalm-suppress PossiblyInvalidArrayAccess
     */
    private function ingestLocations(\PDO $db, BinaryReader $reader, int $run_id): void
    {
        if (!$reader->hasSection(Format::SECTION_LOCATIONS)) {
            return;
        }
        $dict = $reader->getStringDict();
        $count = $reader->getSectionElementCount(Format::SECTION_LOCATIONS);
        if ($count === 0) {
            return;
        }
        $insert = $db->prepare(
            'INSERT INTO context_node_locations'
            . ' (run_id, node_id, address, size, location_type, class_name, string_value,'
            . '  refcount, type_info, region, bin_overhead)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $rows = $reader->castSection(Format::SECTION_LOCATIONS, 'LocationRow');
        if ($rows !== null) {
            for ($i = 0; $i < $count; $i++) {
                $class_id = $rows[$i]->class_id;
                $sv_id = $rows[$i]->string_value_id;
                $region_id = $rows[$i]->region_id;
                $insert->execute([
                    $run_id,
                    $rows[$i]->node_id,
                    $rows[$i]->address,
                    $rows[$i]->size,
                    $dict->lookup($rows[$i]->location_type_id) ?? '',
                    $class_id === Format::NULL_STRING_ID ? null : $dict->lookup($class_id),
                    $sv_id === Format::NULL_STRING_ID ? null : $dict->lookup($sv_id),
                    $rows[$i]->refcount,
                    $rows[$i]->type_info,
                    $region_id === Format::NULL_STRING_ID ? null : $dict->lookup($region_id),
                    $rows[$i]->bin_overhead,
                ]);
            }
            return;
        }
        $data = $reader->getSectionData(Format::SECTION_LOCATIONS);
        for ($i = 0; $i < $count; $i++) {
            $off = $i * Format::LOCATION_ROW_SIZE;
            $row = unpack(
                'Vnode_id/Vlocation_type_id/Vclass_id/Paddress/Psize'
                . '/Vstring_value_id/Vrefcount/Vtype_info/Vregion_id/Vbin_overhead',
                $data,
                $off,
            );
            $class_id = (int)$row['class_id'];
            $sv_id = (int)$row['string_value_id'];
            $region_id = (int)$row['region_id'];
            $insert->execute([
                $run_id,
                (int)$row['node_id'],
                (int)$row['address'],
                (int)$row['size'],
                $dict->lookup((int)$row['location_type_id']) ?? '',
                $class_id === Format::NULL_STRING_ID ? null : $dict->lookup($class_id),
                $sv_id === Format::NULL_STRING_ID ? null : $dict->lookup($sv_id),
                (int)$row['refcount'],
                (int)$row['type_info'],
                $region_id === Format::NULL_STRING_ID ? null : $dict->lookup($region_id),
                (int)$row['bin_overhead'],
            ]);
        }
    }

    /**
     * @psalm-suppress MixedAssignment
     * @psalm-suppress PossiblyInvalidArrayAccess
     */
    private function ingestAttributes(\PDO $db, BinaryReader $reader, int $run_id): void
    {
        if (!$reader->hasSection(Format::SECTION_ATTRIBUTES)) {
            return;
        }
        $count = $reader->getSectionElementCount(Format::SECTION_ATTRIBUTES);
        if ($count === 0) {
            return;
        }
        $dict = $reader->getStringDict();
        $qi = fn (string $id): string => $this->driver->quoteIdentifier($id);
        $insert = $db->prepare(
            "INSERT INTO context_node_attributes (run_id, node_id, {$qi('key')}, {$qi('value')})"
            . ' VALUES (?, ?, ?, ?)'
        );
        $data = $reader->getSectionData(Format::SECTION_ATTRIBUTES);
        $offset = 0;
        for ($i = 0; $i < $count; $i++) {
            $row = unpack('Vnode_id/Vkey_id/Vvalue_id', $data, $offset);
            $offset += 12;
            $key = $dict->lookup((int)$row['key_id']);
            if ($key === null) {
                continue;
            }
            $value_id = (int)$row['value_id'];
            $value = $value_id === Format::NULL_STRING_ID ? null : $dict->lookup($value_id);
            $insert->execute([$run_id, (int)$row['node_id'], $key, $value]);
        }
    }

    private function createTables(\PDO $db): void
    {
        $autoId = $this->driver->autoIncrementPrimaryKey();
        $qi = fn (string $id): string => $this->driver->quoteIdentifier($id);
        $pkText = $this->driver->primaryKeyTextType();

        $db->exec("
            CREATE TABLE IF NOT EXISTS runs (
                run_id {$autoId},
                created_at TEXT NOT NULL
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS summary (
                run_id INTEGER NOT NULL,
                {$qi('key')} {$pkText} NOT NULL,
                {$qi('value')} TEXT,
                PRIMARY KEY (run_id, {$qi('key')})
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS location_types_summary (
                run_id INTEGER NOT NULL,
                {$qi('type')} {$pkText} NOT NULL,
                {$qi('count')} INTEGER NOT NULL,
                memory_usage INTEGER NOT NULL,
                PRIMARY KEY (run_id, {$qi('type')})
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS class_objects_summary (
                run_id INTEGER NOT NULL,
                class_name {$pkText} NOT NULL,
                {$qi('count')} INTEGER NOT NULL,
                memory_usage INTEGER NOT NULL,
                PRIMARY KEY (run_id, class_name)
            )
        ");

        $db->exec('
            CREATE TABLE IF NOT EXISTS context_nodes (
                run_id INTEGER NOT NULL,
                node_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                canonical_node_id INTEGER,
                PRIMARY KEY (run_id, node_id)
            )
        ');

        // `id INTEGER PRIMARY KEY` is the rowid alias on a regular
        // ROWID table — no extra storage cost, but it gives report-time
        // chunked loaders a stable, indexable column they can paginate
        // by (`WHERE run_id = ? AND id > ? LIMIT N`) without SQLite's
        // planner mistakenly using the (run_id, *) covering indexes
        // and post-filtering rowid per chunk.
        $db->exec("
            CREATE TABLE IF NOT EXISTS context_edges (
                id INTEGER PRIMARY KEY,
                run_id INTEGER NOT NULL,
                parent_node_id INTEGER,
                child_node_id INTEGER NOT NULL,
                link_name TEXT NOT NULL,
                is_tree INTEGER NOT NULL,
                strength TEXT NOT NULL DEFAULT 'strong'
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS context_node_locations (
                id {$autoId},
                run_id INTEGER NOT NULL,
                node_id INTEGER NOT NULL,
                address BIGINT,
                size BIGINT,
                location_type TEXT NOT NULL,
                class_name TEXT,
                string_value TEXT,
                refcount BIGINT,
                type_info BIGINT,
                region TEXT,
                bin_overhead BIGINT DEFAULT 0
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS context_node_attributes (
                id {$autoId},
                run_id INTEGER NOT NULL,
                node_id INTEGER NOT NULL,
                {$qi('key')} TEXT NOT NULL,
                {$qi('value')} TEXT
            )
        ");
    }

    private function createIndexes(\PDO $db): void
    {
        // Dropped from the eager build to save analyze time:
        //
        //   - idx_context_edges_run_strength
        //       Fully covered by the NonTreeEdgePass partial index below
        //       (whose WHERE clause already pins is_tree=0 AND
        //       strength='strong'). No query hit this index alone —
        //       every strength filter we ship is paired with is_tree.
        //
        //   - idx_context_edges_run_parent_tree
        //       The only Phase 3 callers (CycleClusterPass /
        //       GcPendingPass / BlameAllocationPass loadRootLinkNames
        //       + the walker passes) have been rewritten to use the
        //       substrate's in-memory treeParentIdx / treeLinkIds
        //       indexes, so no SQL query filters by `parent_node_id
        //       = X AND is_tree = Y` on the Phase 3 path anymore.
        //       Phase 2 SQL fallbacks still exist but fall back to a
        //       full scan, which is acceptable for the small-graph
        //       case they target.
        //
        // Together these were ~5.7% of analyze wall time on the
        // analyze.rbt trace (roughly 2 minutes out of 36).
        //
        // Build-phase PRAGMAs: createIndexes is the single biggest
        // analyze hot spot (~22% of wall time on analyze3.rbt). The
        // tunes below trade durability for build speed — safe because
        // we are still inside the same PDO session that wrote the
        // table data, the file is not yet visible to readers, and a
        // crash during analyze just means the user re-runs analyze.
        // The original settings are restored at the end so the
        // resulting database is left in normal-durability mode for
        // subsequent report runs.
        $sqlite = $this->driver instanceof SqliteDriver;
        $saved_synchronous = '';
        $saved_journal_mode = '';
        $saved_temp_store = '';
        if ($sqlite) {
            $saved_synchronous = Cast::toString($db->query('PRAGMA synchronous')->fetchColumn());
            $saved_journal_mode = Cast::toString($db->query('PRAGMA journal_mode')->fetchColumn());
            $saved_temp_store = Cast::toString($db->query('PRAGMA temp_store')->fetchColumn());
            $db->exec('PRAGMA synchronous = OFF');
            $db->exec('PRAGMA journal_mode = MEMORY');
            $db->exec('PRAGMA temp_store = MEMORY');
            // -1048576 means 1 GiB of page cache (negative = KiB).
            // The B-tree build for the partial covering index below
            // benefits enormously from being able to keep the working
            // set in memory; on smaller machines SQLite will simply
            // honour what it can.
            $db->exec('PRAGMA cache_size = -1048576');
        }

        try {
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_context_edges_run_child'
                . ' ON context_edges(run_id, child_node_id)'
            );
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_context_node_locations_run_node'
                . ' ON context_node_locations(run_id, node_id)'
            );
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_context_node_locations_run_class'
                . ' ON context_node_locations(run_id, class_name)'
            );
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_context_node_attributes_run_node'
                . ' ON context_node_attributes(run_id, node_id)'
            );
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_context_node_locations_run_type'
                . ' ON context_node_locations(run_id, location_type)'
            );
            // Lets TopStringsPass scan the top-N largest strings as a backward
            // index range scan instead of sorting every ZendStringMemoryLocation
            // row in the table — critical on captures with millions of strings.
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_context_node_locations_run_type_size'
                . ' ON context_node_locations(run_id, location_type, size)'
            );
            // (run_id, link_name, parent_node_id) — used by the
            // v_arrays view JOIN, DynamicPropertiesPass /
            // StructuralDedupPass / PropertyScalingPass JOINs that
            // narrow on a literal link_name and a parent_node_id.
            // The trailing is_tree column was dropped: SQLite was
            // only ever using it as a stored value (the WHEREs filter
            // is_tree as a post-condition, not a leading key), so the
            // extra column was paying B-tree page cost for nothing.
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_context_edges_run_link_parent'
                . ' ON context_edges(run_id, link_name, parent_node_id)'
            );
            // Partial covering index for the NonTreeEdgePass
            // aggregations:
            //   WHERE run_id = ? AND is_tree = 0 AND strength = 'strong'
            //   GROUP BY link_name
            // The constants `is_tree = 0` and `strength = 'strong'`
            // are pushed into the partial-index WHERE so SQLite only
            // stores rows that actually match — typically a tiny
            // fraction of the edge table on a real PHP heap (most
            // edges are tree edges). The remaining columns are
            // ordered (link_name, child_node_id, parent_node_id) so
            // the GROUP BY link_name + the per-link
            // count(distinct child_node_id) / min(parent_node_id)
            // projections can finish without ever leaving the index.
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_context_edges_strong_nontree_links'
                . ' ON context_edges(run_id, link_name, child_node_id, parent_node_id)'
                . " WHERE is_tree = 0 AND strength = 'strong'"
            );
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_context_nodes_canonical'
                . ' ON context_nodes(run_id, canonical_node_id)'
            );
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_context_node_locations_region_addr_size'
                . ' ON context_node_locations(run_id, region, address, size)'
            );
        } finally {
            if ($sqlite) {
                $db->exec('PRAGMA synchronous = ' . $saved_synchronous);
                $db->exec('PRAGMA journal_mode = ' . $saved_journal_mode);
                $db->exec('PRAGMA temp_store = ' . $saved_temp_store);
            }
        }
    }

    private function createViews(\PDO $db): void
    {
        $concat = fn (string $a, string $b): string => $this->driver->concatExpr($a, $b);
        $createView = fn (string $name): string => $this->driver->createViewSql($name);
        $qi = fn (string $id): string => $this->driver->quoteIdentifier($id);

        $pathExpr = $concat('np.path', $concat("' -> '", 'e.link_name'));

        $db->exec("
            {$createView('v_node_paths')}
            WITH RECURSIVE node_paths(run_id, node_id, path, depth) AS (
                SELECT run_id, child_node_id, link_name, 0
                FROM context_edges
                WHERE parent_node_id IS NULL AND is_tree = 1
              UNION ALL
                SELECT np.run_id, e.child_node_id, {$pathExpr}, np.depth + 1
                FROM context_edges e
                JOIN node_paths np ON e.parent_node_id = np.node_id AND e.run_id = np.run_id
                WHERE e.is_tree = 1
            )
            SELECT run_id, node_id, path, depth FROM node_paths
        ");

        $db->exec("
            {$createView('v_arrays')}
            SELECT
                header_cn.run_id,
                header_cn.node_id,
                header_loc.address,
                header_loc.size AS header_size,
                COALESCE(table_loc.size, 0) AS table_size,
                header_loc.size + COALESCE(table_loc.size, 0) AS total_size,
                {$this->driver->castAsInteger("cnt.{$qi('value')}")} AS element_count,
                header_loc.refcount
            FROM context_nodes header_cn
            JOIN context_node_locations header_loc
                ON header_loc.run_id = header_cn.run_id
                AND header_loc.node_id = header_cn.node_id
                AND header_loc.location_type = 'ZendArrayMemoryLocation'
            LEFT JOIN context_edges elements_edge
                ON elements_edge.run_id = header_cn.run_id
                AND elements_edge.parent_node_id = header_cn.node_id
                AND elements_edge.link_name = 'array_elements'
                AND elements_edge.is_tree = 1
            LEFT JOIN context_node_locations table_loc
                ON table_loc.run_id = elements_edge.run_id
                AND table_loc.node_id = elements_edge.child_node_id
                AND table_loc.location_type = 'ZendArrayTableMemoryLocation'
            LEFT JOIN context_node_attributes cnt
                ON cnt.run_id = elements_edge.run_id
                AND cnt.node_id = elements_edge.child_node_id
                AND cnt.{$qi('key')} = '#count'
        ");
    }

    private function insertRun(\PDO $db): int
    {
        $db->exec("INSERT INTO runs (created_at) VALUES ('" . gmdate('Y-m-d\TH:i:s\Z') . "')");
        return (int)$db->lastInsertId();
    }

    /**
     * @param array<int, array<string, mixed>> $summary
     */
    private function insertSummary(\PDO $db, int $run_id, array $summary): void
    {
        $qi = fn (string $id): string => $this->driver->quoteIdentifier($id);
        $stmt = $db->prepare(
            "INSERT INTO summary (run_id, {$qi('key')}, {$qi('value')}) VALUES (:run_id, :key, :value)"
        );
        foreach ($summary as $entry) {
            /** @psalm-suppress MixedAssignment -- $entry is array<string, mixed> */
            foreach ($entry as $key => $value) {
                $string_value = is_scalar($value) ? (string)$value : json_encode($value);
                assert(is_string($string_value));
                $stmt->execute([
                    ':run_id' => $run_id,
                    ':key' => $key,
                    ':value' => $string_value,
                ]);
            }
        }
    }

    private function insertLocationTypesSummaryFromDb(\PDO $db, int $run_id): void
    {
        $qi = fn (string $id): string => $this->driver->quoteIdentifier($id);
        $stmt = $db->prepare("
            INSERT INTO location_types_summary (run_id, {$qi('type')}, {$qi('count')}, memory_usage)
            SELECT ?, location_type, COUNT(*), SUM(size)
            FROM context_node_locations
            WHERE run_id = ?
              AND (region IN ('zend_mm_heap', 'zend_mm_huge', 'vm_stack', 'compiler_arena')
                   OR region IS NULL)
            GROUP BY location_type
            ORDER BY SUM(size) DESC
        ");
        $stmt->execute([$run_id, $run_id]);
    }

    private function insertClassObjectsSummaryFromDb(\PDO $db, int $run_id): void
    {
        $qi = fn (string $id): string => $this->driver->quoteIdentifier($id);
        $stmt = $db->prepare("
            INSERT INTO class_objects_summary (run_id, class_name, {$qi('count')}, memory_usage)
            SELECT ?, class_name, COUNT(*), SUM(size)
            FROM context_node_locations
            WHERE run_id = ?
              AND class_name IS NOT NULL
              AND (region IN ('zend_mm_heap', 'zend_mm_huge', 'vm_stack', 'compiler_arena')
                   OR region IS NULL)
            GROUP BY class_name
            ORDER BY SUM(size) DESC
        ");
        $stmt->execute([$run_id, $run_id]);
    }

    /**
     * Compute canonical_node_id for nodes that share the same memory address.
     *
     * When the same PHP object is observed from multiple collection phases
     * (e.g. objects_store and call_frames in streaming mode), each phase
     * creates a separate graph node. This method groups those nodes by their
     * memory address and assigns the smallest node_id in each group as the
     * canonical representative.
     *
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument
     */
    private function computeCanonicalNodeIds(\PDO $db, int $run_id): void
    {
        $rows = $db->query(
            "SELECT address, GROUP_CONCAT(DISTINCT node_id) AS node_ids"
            . " FROM context_node_locations"
            . " WHERE run_id = {$run_id} AND address IS NOT NULL"
            . " GROUP BY address"
            . " HAVING COUNT(DISTINCT node_id) > 1"
        )->fetchAll(\PDO::FETCH_NUM);

        if (!$rows) {
            return;
        }

        $stmt = $db->prepare(
            "UPDATE context_nodes SET canonical_node_id = ? WHERE run_id = ? AND node_id = ?"
        );
        foreach ($rows as $r) {
            $node_ids = array_map('intval', explode(',', (string)$r[1]));
            $canon = min($node_ids);
            foreach ($node_ids as $nid) {
                $stmt->execute([$canon, $run_id, $nid]);
            }
        }
    }

    /**
     * Copy all data from a pre-populated (temp) SQLite DB to the target DB.
     */
    private function copyFromPrePopulatedDb(\PDO $source, int $source_run_id, \PDO $target): void
    {
        $qi = fn (string $id): string => $this->driver->quoteIdentifier($id);

        $run_id = $this->insertRun($target);

        // Copy summary
        $rows = $source->prepare('SELECT "key", "value" FROM summary WHERE run_id = ?');
        $rows->execute([$source_run_id]);
        $insert = $target->prepare(
            "INSERT INTO summary (run_id, {$qi('key')}, {$qi('value')}) VALUES (?, ?, ?)"
        );
        /** @psalm-suppress MixedAssignment */
        while ($row = $rows->fetch(\PDO::FETCH_ASSOC)) {
            /** @psalm-suppress MixedArrayAccess */
            $insert->execute([$run_id, $row['key'], $row['value']]);
        }

        // Copy context_nodes
        $rows = $source->prepare(
            'SELECT node_id, type, canonical_node_id FROM context_nodes WHERE run_id = ?'
        );
        $rows->execute([$source_run_id]);
        $insert = $target->prepare(
            $this->driver->insertIgnoreSql(
                'context_nodes',
                'run_id, node_id, type, canonical_node_id',
                '?, ?, ?, ?'
            )
        );
        /** @psalm-suppress MixedAssignment */
        while ($row = $rows->fetch(\PDO::FETCH_ASSOC)) {
            /** @psalm-suppress MixedArrayAccess */
            $insert->execute([$run_id, $row['node_id'], $row['type'], $row['canonical_node_id']]);
        }

        // Copy context_edges
        $rows = $source->prepare(
            'SELECT parent_node_id, child_node_id, link_name, is_tree, strength'
            . ' FROM context_edges WHERE run_id = ?'
        );
        $rows->execute([$source_run_id]);
        $insert = $target->prepare(
            'INSERT INTO context_edges (run_id, parent_node_id, child_node_id, link_name, is_tree, strength)'
            . ' VALUES (?, ?, ?, ?, ?, ?)'
        );
        /** @psalm-suppress MixedAssignment */
        while ($row = $rows->fetch(\PDO::FETCH_ASSOC)) {
            /** @psalm-suppress MixedArrayAccess */
            $insert->execute([
                $run_id, $row['parent_node_id'], $row['child_node_id'],
                $row['link_name'], $row['is_tree'], $row['strength'] ?? 'strong',
            ]);
        }

        // Copy context_node_locations
        $rows = $source->prepare(
            'SELECT node_id, address, size, location_type, class_name, string_value, refcount, type_info, region'
            . ' FROM context_node_locations WHERE run_id = ?'
        );
        $rows->execute([$source_run_id]);
        $insert = $target->prepare(
            'INSERT INTO context_node_locations'
            . ' (run_id, node_id, address, size, location_type, class_name, string_value, refcount, type_info, region)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        /** @psalm-suppress MixedAssignment */
        while ($row = $rows->fetch(\PDO::FETCH_ASSOC)) {
            /** @psalm-suppress MixedArrayAccess */
            $insert->execute([
                $run_id, $row['node_id'], $row['address'], $row['size'], $row['location_type'],
                $row['class_name'], $row['string_value'], $row['refcount'], $row['type_info'], $row['region'],
            ]);
        }

        // Copy context_node_attributes
        $rows = $source->prepare(
            "SELECT node_id, {$qi('key')}, {$qi('value')} FROM context_node_attributes WHERE run_id = ?"
        );
        $rows->execute([$source_run_id]);
        $insert = $target->prepare(
            "INSERT INTO context_node_attributes (run_id, node_id, {$qi('key')}, {$qi('value')})"
            . ' VALUES (?, ?, ?, ?)'
        );
        /** @psalm-suppress MixedAssignment */
        while ($row = $rows->fetch(\PDO::FETCH_ASSOC)) {
            /** @psalm-suppress MixedArrayAccess */
            $insert->execute([$run_id, $row['node_id'], $row['key'], $row['value']]);
        }

        // Compute summaries from copied data
        $this->insertLocationTypesSummaryFromDb($target, $run_id);
        $this->insertClassObjectsSummaryFromDb($target, $run_id);
    }
}
