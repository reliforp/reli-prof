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
use Reli\Inspector\Output\MemoryOutput\SqliteRaw\CellTooLargeException;
use Reli\Inspector\Output\MemoryOutput\SqliteRaw\IntegerIndexMerger;
use Reli\Inspector\Output\MemoryOutput\SqliteRaw\OverflowNotSupportedException;
use Reli\Inspector\Output\MemoryOutput\SqliteRaw\ParallelIndexBuilder;
use Reli\Inspector\Output\MemoryOutput\SqliteRaw\Reader as SqliteRawReader;
use Reli\Inspector\Output\MemoryOutput\SqliteRaw\SharedMmapPlan;
use Reli\Inspector\Output\MemoryOutput\SqliteRaw\SharedMmapTableWriter;
use Reli\Inspector\Output\MemoryOutput\SqliteRaw\SortRunReader;
use Reli\Inspector\Output\MemoryOutput\SqliteRaw\SortRunWriter;
use Reli\Inspector\Output\MemoryOutput\SqliteRaw\SqliteFileMaintainer;
use Reli\Inspector\Output\MemoryOutput\SqliteRaw\TableMerger;
use Reli\Inspector\Output\MemoryOutput\SqliteRaw\Writer as SqliteRawWriter;
use Reli\Lib\File\LibcFileWriter;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\ContextAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\PdoContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionBoundaries;

/**
 * PDO-driven memory output (sqlite3 / mysql / postgresql).
 *
 * @todo This class has accumulated several concerns — rmem serial
 *       ingest, parallel-shard ingest, shared-mmap ingest, raw
 *       SQLite merge orchestration, integer-index merge
 *       orchestration, per-table row generators, worker management.
 *       Now that the rmem-canonical migration has settled, it's
 *       worth splitting into smaller collaborators (e.g.
 *       RmemToPdoIngestor / SqliteRmemParallelIngestor /
 *       SqliteSharedMmapIngestor / RmemRowProjector). Deferred to
 *       a follow-up so the unify-memory-output PR doesn't grow
 *       further; the public surface here is a single
 *       MemoryOutputInterface::output, so an internal decomposition
 *       is non-breaking.
 */
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
            if (
                self::sharedMmapIngestEnabled()
                && extension_loaded('ffi')
                && $this->passesSharedMmapPreflight($rmem_path)
            ) {
                // Pre-flight passed — committed to shared-mmap. Any
                // failure inside ingestFromRmemSharedMmap throws
                // rather than falls back, because the target DB has
                // already been mutated (schema, run, summary, file
                // size) by the time we get past allocateRun().
                $this->ingestFromRmemSharedMmap($rmem_path, $summary);
                return;
            }
            $this->ingestFromRmemParallel($rmem_path, $summary);
            return;
        }
        $this->ingestFromRmemSerial($rmem_path, $summary);
    }

    private static function sharedMmapIngestEnabled(): bool
    {
        return getenv('RELI_SHARED_MMAP_INGEST') === '1';
    }

    /**
     * Cheap heuristic pre-flight for the shared-mmap path. Runs
     * before any side effect on the target DB. Returns true to
     * permit the dispatch to commit to {@see ingestFromRmemSharedMmap},
     * false to fall back to the regular parallel-shard path.
     *
     * **Heuristic, not a safety guarantee.** The check looks at the
     * rmem string dict and bails on any string > 1 KiB; a single
     * big string can blow past the inline cell limit when combined
     * with the rest of a row's columns. A row whose individual
     * strings each fit under 1 KiB but whose combined columns
     * exceed `INLINE_PAYLOAD_LIMIT` will still throw
     * CellTooLargeException at runtime — surface as a fatal error
     * after the DB has already been mutated. The 1 KiB cutoff is
     * tuned to keep that runtime case rare in practice; in
     * production use, bumping it should track row-shape changes.
     */
    private function passesSharedMmapPreflight(string $rmem_path): bool
    {
        $reader = BinaryReader::open($rmem_path);
        $dict = $reader->getStringDict();
        for ($i = 0, $n = $dict->count(); $i < $n; $i++) {
            $s = $dict->lookup($i);
            if ($s !== null && strlen($s) > 1024) {
                return false;
            }
        }
        return true;
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
     * Forks N worker processes; each owns one shard SQLite file
     * containing all four data tables (context_nodes, context_edges,
     * context_node_locations, context_node_attributes), with each
     * worker responsible for a contiguous rowid range
     * `[i * count / N, (i + 1) * count / N)` of every rmem section.
     * Workers run concurrently because their shard files are
     * disjoint, sidestepping SQLite's per-file writer lock.
     *
     * Per-section row ranges are preserved exactly into the shard
     * tables (run_id stored as 0 placeholder) so a future merge step
     * can stitch the rowid B-tree pages directly without re-sorting.
     * The current merge still uses `INSERT INTO main.t SELECT ?, ...
     * FROM s_i.t`, which serializes the b-tree append; that's the
     * bottleneck the format-direct merge step is meant to remove.
     *
     * @param array<int, array<string, mixed>> $summary
     * @param int|null $worker_count number of shards; defaults to a
     *                               cap of 4 to match the table-grain
     *                               sharding's wall-clock floor
     * @psalm-suppress UnusedFunctionCall
     */
    public function ingestFromRmemParallel(
        string $rmem_path,
        array $summary,
        ?int $worker_count = null,
    ): void {
        $worker_count = $worker_count ?? $this->defaultWorkerCount();

        $shard_paths = [];
        for ($i = 0; $i < $worker_count; $i++) {
            $base = tempnam(sys_get_temp_dir(), "reli_shard_{$i}_");
            if ($base === false) {
                $this->cleanupShards($shard_paths);
                throw new \RuntimeException('Failed to create temporary file for shard');
            }
            $shard_paths[$i] = $base . '.sqlite3';
            @unlink($base);
        }

        try {
            // Snapshot each section's element count once so all
            // workers and the merge phase agree on the slicing.
            $reader = BinaryReader::open($rmem_path);
            $section_counts = [
                Format::SECTION_NODES => $reader->hasSection(Format::SECTION_NODES)
                    ? $reader->getSectionElementCount(Format::SECTION_NODES) : 0,
                Format::SECTION_EDGES => $reader->hasSection(Format::SECTION_EDGES)
                    ? $reader->getSectionElementCount(Format::SECTION_EDGES) : 0,
                Format::SECTION_LOCATIONS => $reader->hasSection(Format::SECTION_LOCATIONS)
                    ? $reader->getSectionElementCount(Format::SECTION_LOCATIONS) : 0,
                Format::SECTION_ATTRIBUTES => $reader->hasSection(Format::SECTION_ATTRIBUTES)
                    ? $reader->getSectionElementCount(Format::SECTION_ATTRIBUTES) : 0,
            ];
            unset($reader);

            // Allocate the run_id up-front so workers can stamp it
            // into every row they emit. This eliminates the
            // placeholder/UPDATE dance the earlier draft used:
            // shards used to write run_id = 0 and the merge phase
            // had to REINDEX + UPDATE everything to run_id = 1
            // before the integer indexes were correct. Doing the
            // allocation now means cells are already canonical the
            // first time they hit disk.
            $run_id = $this->allocateRun($summary);

            $pids = [];
            for ($i = 0; $i < $worker_count; $i++) {
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
                        $this->writeShard(
                            $rmem_path,
                            $shard_paths[$i],
                            $i,
                            $worker_count,
                            $section_counts,
                            $run_id,
                        );
                        exit(0);
                    } catch (\Throwable $e) {
                        fwrite(STDERR, "shard {$i} worker failed: " . $e->getMessage() . "\n");
                        exit(1);
                    }
                }
                $pids[$i] = $pid;
            }

            $failed = [];
            foreach ($pids as $i => $pid) {
                pcntl_waitpid($pid, $status);
                if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                    $failed[] = $i;
                }
            }
            if ($failed !== []) {
                throw new \RuntimeException(
                    'Parallel shard workers failed: shards ' . implode(', ', $failed)
                );
            }

            $this->mergeShards($shard_paths, $run_id);
        } finally {
            $this->cleanupSortRuns($shard_paths);
            $this->cleanupShards($shard_paths);
        }
    }

    /**
     * Shared-mmap parallel rmem -> SQLite bulk-load.
     *
     * Replaces the shard-SQLite + ATTACH/MERGE pipeline with direct
     * page-level writes. Forked workers each fill a pre-assigned
     * range of leaf pages of the same `mmap`'d main DB file (no
     * shard files, no merge phase). Parent then assembles each
     * table's interior b-tree from the worker leaves and places the
     * root at the table's existing `sqlite_master.rootpage`. The
     * remaining serial work — REINDEX of autoindexes, CREATE INDEX
     * for user indexes, summaries, canonical IDs — is identical to
     * the other paths.
     *
     * **Commits the target DB on entry**: callers must have decided
     * via {@see passesSharedMmapPreflight} that this path is safe
     * before invoking it. Once we call {@see allocateRun}, the DB
     * has the schema + run + summary written to it, and any
     * subsequent failure throws — falling back to
     * ingestFromRmemParallel against a half-populated DB would
     * double-insert and corrupt. CellTooLargeException at runtime
     * (mid-fork overflow that the dict-only pre-flight missed) also
     * surfaces as a fatal RuntimeException.
     *
     * **Experimental, gated by `RELI_SHARED_MMAP_INGEST=1`.** Not
     * the default yet. On a fatal failure after the commit point,
     * the output DB will be left in an incomplete state — the
     * file's data tables may be partially populated with no
     * indexes / summaries / canonical IDs. Recover by deleting the
     * output file and retrying without the env var, which routes
     * through the parallel-shard path that tolerates large rows
     * and handles mid-run failures safely.
     *
     * @param array<int, array<string, mixed>> $summary
     * @psalm-suppress InaccessibleMethod
     * @psalm-suppress UndefinedPropertyFetch
     * @psalm-suppress InvalidPropertyFetch
     * @psalm-suppress MixedAssignment
     * @psalm-suppress MixedArgument
     * @psalm-suppress UnusedFunctionCall
     */
    public function ingestFromRmemSharedMmap(
        string $rmem_path,
        array $summary,
        ?int $worker_count = null,
    ): void {
        if (!$this->driver instanceof SqliteDriver) {
            throw new \RuntimeException(
                'ingestFromRmemSharedMmap requires SqliteDriver'
            );
        }
        $worker_count = $worker_count ?? $this->defaultWorkerCount();
        $log_phases = getenv('RELI_SHARED_MMAP_PHASES') === '1';
        $phase_t = microtime(true);
        $phase = static function (string $label) use (&$phase_t, $log_phases): void {
            if (!$log_phases) {
                return;
            }
            $now = microtime(true);
            fwrite(STDERR, sprintf("[smtw phase] %-32s %6.3fs\n", $label, $now - $phase_t));
            $phase_t = $now;
        };

        // String-dict pre-flight has already been done by
        // passesSharedMmapPreflight (called by the dispatch); here we
        // only count rows. From this point on we commit to the
        // shared-mmap path — failures throw rather than fall back,
        // because allocateRun() will mutate the target DB.
        $reader = BinaryReader::open($rmem_path);
        $section_counts = [
            'context_nodes' => $reader->hasSection(Format::SECTION_NODES)
                ? $reader->getSectionElementCount(Format::SECTION_NODES) : 0,
            'context_edges' => $reader->hasSection(Format::SECTION_EDGES)
                ? $reader->getSectionElementCount(Format::SECTION_EDGES) : 0,
            'context_node_locations' => $reader->hasSection(Format::SECTION_LOCATIONS)
                ? $reader->getSectionElementCount(Format::SECTION_LOCATIONS) : 0,
            'context_node_attributes' => $reader->hasSection(Format::SECTION_ATTRIBUTES)
                ? $reader->getSectionElementCount(Format::SECTION_ATTRIBUTES) : 0,
        ];
        unset($reader);
        $phase('section count read');

        $run_id = $this->allocateRun($summary);
        $phase('allocateRun (schema+run+summary)');

        $db = $this->driver->createConnection();
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $rootpages = [];
        $stmt = $db->query(
            "SELECT name, rootpage FROM sqlite_master WHERE type='table' AND name LIKE 'context_%'"
        );
        if ($stmt !== false) {
            /** @var array{name: string, rootpage: int} $row */
            foreach ($stmt as $row) {
                $rootpages[$row['name']] = $row['rootpage'];
            }
        }
        $current_pages = (int)$db->query('PRAGMA page_count')->fetchColumn();
        unset($stmt, $db);

        // Estimate per-table average cell size by sampling the first
        // few hundred rows of each section. Uses a fresh BinaryReader
        // so we don't pin the rmem mapping into the parent for the
        // rest of the run.
        $est_avg_cell_bytes = $this->estimateAvgCellBytes($rmem_path, $section_counts, $run_id);
        $phase('estimateAvgCellBytes');

        // Plan layout. Pages can hold ~3500 bytes after header; we
        // size the reservation to fit the estimated row total plus
        // a 15% safety margin and round up to whole pages.
        $tables = [];
        foreach ($section_counts as $name => $count) {
            if ($count === 0) {
                continue;
            }
            if (!isset($rootpages[$name])) {
                throw new \RuntimeException(
                    "shared-mmap ingest: missing rootpage for table {$name}"
                );
            }
            $rows_per_worker = (int)ceil($count / $worker_count);
            $bytes_per_row = (int)ceil((float)($est_avg_cell_bytes[$name] ?? 100) * 1.15);
            $est_leaves = (int)ceil(($rows_per_worker * $bytes_per_row) / 3500);
            $tables[$name] = [$count, max(1, $est_leaves)];
        }
        if ($tables === []) {
            // No data sections to ingest — schema + run + summary
            // are already in place from allocateRun(); we still
            // need to run the post-passes the regular ingest does
            // so the empty DB matches direct-write parity.
            $db = $this->driver->createConnection();
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->insertLocationTypesSummaryFromDb($db, $run_id);
            $this->insertClassObjectsSummaryFromDb($db, $run_id);
            $this->computeCanonicalNodeIds($db, $run_id);
            $this->driver->afterBulkInsert($db);
            $this->createIndexes($db);
            $this->createViews($db);
            return;
        }

        $plan = SharedMmapTableWriter::plan(
            tables: $tables,
            first_pgno: $current_pages + 1,
            worker_count: $worker_count,
            interior_reserve_per_table: 32,
        );
        $total_pages = $current_pages + $plan->totalPagesUsed();

        $db_path = $this->driver->path();
        $meta_path = $db_path . '.smtw_meta';

        // Pre-grow the DB file via libc ftruncate.
        $libc = \FFI::cdef('int open(const char *, int, int);', null);
        /** @var int $fd */
        $fd = $libc->open($db_path, 2, 0);
        if ($fd < 0 || !LibcFileWriter::ftruncate($fd, $total_pages * 4096)) {
            if ($fd >= 0) {
                LibcFileWriter::close($fd);
            }
            throw new \RuntimeException(
                "shared-mmap ingest: failed to ftruncate {$db_path} to {$total_pages} pages"
            );
        }
        LibcFileWriter::close($fd);

        // Pre-grow the meta file.
        file_put_contents($meta_path, str_repeat("\x00", $plan->metaTotalBytes()));

        $data_res = LibcFileWriter::mmapShared($db_path, $total_pages * 4096);
        $meta_res = LibcFileWriter::mmapShared($meta_path, $plan->metaTotalBytes());
        if ($data_res === null || $meta_res === null) {
            if ($data_res !== null) {
                LibcFileWriter::munmap($data_res[0], $data_res[1]);
                LibcFileWriter::close($data_res[2]);
            }
            if ($meta_res !== null) {
                LibcFileWriter::munmap($meta_res[0], $meta_res[1]);
                LibcFileWriter::close($meta_res[2]);
            }
            @unlink($meta_path);
            throw new \RuntimeException(
                'shared-mmap ingest: failed to mmap data and/or meta region'
            );
        }
        [$data_ptr, $data_len, $data_fd] = $data_res;
        [$meta_ptr, $meta_len, $meta_fd] = $meta_res;

        $had_overflow = false;
        $other_failure = false;
        try {
            $pids = [];
            for ($w = 0; $w < $worker_count; $w++) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    foreach ($pids as $p) {
                        posix_kill($p, SIGTERM);
                        pcntl_waitpid($p, $_status);
                    }
                    $other_failure = true;
                    break;
                }
                if ($pid === 0) {
                    try {
                        $this->workerWriteAllTables(
                            $data_ptr,
                            $meta_ptr,
                            $plan,
                            $rmem_path,
                            $w,
                            $worker_count,
                            $section_counts,
                            $run_id,
                        );
                        exit(0);
                    } catch (CellTooLargeException $e) {
                        fwrite(STDERR, "shared-mmap worker {$w} overflow: " . $e->getMessage() . "\n");
                        exit(2);
                    } catch (\Throwable $e) {
                        fwrite(STDERR, "shared-mmap worker {$w} failed: " . $e->getMessage() . "\n");
                        exit(1);
                    }
                }
                $pids[] = $pid;
            }
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
                $code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 1;
                if ($code === 2) {
                    $had_overflow = true;
                } elseif ($code !== 0) {
                    $other_failure = true;
                }
            }
            $phase('fork + workers (leaf page writes)');
            if ($had_overflow) {
                // CellTooLargeException at runtime — the dict-only
                // pre-flight (passesSharedMmapPreflight) didn't catch
                // this because a single row's combined columns can
                // overflow the inline payload limit even when each
                // individual string fits. The target DB has been
                // mutated; we can't fall back. Surface as fatal so
                // the user knows to disable RELI_SHARED_MMAP_INGEST.
                throw new \RuntimeException(
                    'shared-mmap ingest: a row exceeded the inline payload '
                    . 'limit at runtime; the output DB is incomplete. '
                    . 'Delete the output file and retry without '
                    . 'RELI_SHARED_MMAP_INGEST — the parallel-shard path '
                    . 'tolerates large rows via overflow chains.'
                );
            }
            if ($other_failure) {
                throw new \RuntimeException(
                    'shared-mmap ingest: one or more workers exited non-zero; '
                    . 'the output DB is incomplete. Delete the output file '
                    . 'and retry, optionally without RELI_SHARED_MMAP_INGEST '
                    . 'to fall back to the parallel-shard path.'
                );
            }

            $all_unused = [];
            foreach ($plan->tableNames() as $table_name) {
                $unused = SharedMmapTableWriter::mainAssembleInteriorTree(
                    data_ptr: $data_ptr,
                    meta_ptr: $meta_ptr,
                    plan: $plan,
                    table_name: $table_name,
                    worker_count: $worker_count,
                    existing_rootpage: $rootpages[$table_name],
                    first_mapped_pgno: 1,
                );
                $all_unused = array_merge($all_unused, $unused);
            }

            // Drop trailing unused pages from the file so the
            // shared-mmap path doesn't bloat the DB by the worker
            // safety-margin reservation. Remaining mid-file unused
            // pages still need freelist entries.
            $effective_total_pages = $total_pages;
            if ($all_unused !== []) {
                sort($all_unused);
                $last_used = $total_pages;
                while (
                    $all_unused !== []
                    && $all_unused[count($all_unused) - 1] === $last_used
                ) {
                    array_pop($all_unused);
                    $last_used--;
                }
                $effective_total_pages = $last_used;
            }

            SqliteFileMaintainer::finalize(
                data_ptr: $data_ptr,
                first_mapped_pgno: 1,
                total_pages: $effective_total_pages,
                unused_pgnos: $all_unused,
            );
            $phase('main interior assembly + finalize');
            LibcFileWriter::msync($data_ptr, $data_len);
            $phase('msync');
        } finally {
            LibcFileWriter::munmap($data_ptr, $data_len);
            LibcFileWriter::munmap($meta_ptr, $meta_len);
            LibcFileWriter::close($meta_fd);
            // Truncate the data file to the effective page count so
            // the trailing safety-margin pages don't remain on disk.
            // (Done after munmap so the shrinking truncate doesn't
            // race with the still-mapped region.)
            if (isset($effective_total_pages) && $effective_total_pages < $total_pages) {
                LibcFileWriter::ftruncate($data_fd, $effective_total_pages * 4096);
            }
            LibcFileWriter::close($data_fd);
            @unlink($meta_path);
        }

        // Post-passes via PDO. REINDEX rebuilds the autoindexes for
        // PRIMARY KEY constraints; createIndexes adds the user-
        // defined indexes; the summary/canonical helpers do the
        // serial cross-table work.
        $db = $this->driver->createConnection();
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA synchronous = OFF');
        $db->exec('PRAGMA temp_store = MEMORY');
        foreach ($plan->tableNames() as $table_name) {
            $db->exec("REINDEX {$table_name}");
        }
        $phase('REINDEX (autoindexes)');
        $this->insertLocationTypesSummaryFromDb($db, $run_id);
        $phase('insertLocationTypesSummaryFromDb');
        $this->insertClassObjectsSummaryFromDb($db, $run_id);
        $phase('insertClassObjectsSummaryFromDb');
        $this->computeCanonicalNodeIds($db, $run_id);
        $phase('computeCanonicalNodeIds');
        $this->driver->afterBulkInsert($db);

        if (self::parallelIndexEnabled() && $this->tryParallelCreateIndexes($db)) {
            $phase('createIndexes (parallel sharded)');
        } else {
            $this->createIndexes($db);
            $phase('createIndexes');
        }
        $this->createViews($db);
        $phase('createViews');
    }

    private static function parallelIndexEnabled(): bool
    {
        return getenv('RELI_PARALLEL_INDEX') === '1';
    }

    /**
     * Per-index sharded CREATE INDEX. Returns true if the parallel
     * path completed and the caller can skip the serial
     * createIndexes(). Returns false only on ParallelIndexBuilder's
     * cheap pre-flight bail (non-Sqlite driver, FFI/pcntl
     * unavailable) — at that point nothing has been written, so
     * falling back to the serial createIndexes is safe. Once
     * ParallelIndexBuilder commits, any failure throws rather than
     * returning null/false; we let those bubble up to surface as a
     * fatal error, because falling back to serial createIndexes
     * after partial parallel writes would leave orphan index pages
     * that PRAGMA integrity_check flags.
     *
     * Each (name, table, sql) tuple matches one statement that
     * createIndexes() would have run; ParallelIndexBuilder forks
     * workers, each cp's main, runs CREATE INDEX natively in its
     * shard, then page-relocates the index back into main via
     * shared mmap.
     */
    private function tryParallelCreateIndexes(\PDO $db): bool
    {
        if (!$this->driver instanceof SqliteDriver) {
            return false;
        }
        $specs = self::userIndexSpecs();
        $db_path = $this->driver->path();

        // Reservation budget per index, tuned to the largest source
        // table's row count. The shard subset table is rowid-aligned
        // with main, so the per-shard index has roughly the same
        // page count as the equivalent index built directly on
        // main. Worst-case entry size in our schema is ~70 bytes
        // (TEXT key + rowid varint), giving ~55 entries per leaf
        // page → ceil(rows/50) pages of leaves plus a constant for
        // interior + slack.
        $max_rows = 0;
        foreach (['context_nodes', 'context_edges', 'context_node_locations', 'context_node_attributes'] as $tbl) {
            try {
                $count = (int)$db->query("SELECT COUNT(*) FROM {$tbl}")->fetchColumn();
                if ($count > $max_rows) {
                    $max_rows = $count;
                }
            } catch (\Throwable) {
                // table may not exist; skip
            }
        }
        $reservation = max(
            ParallelIndexBuilder::PAGES_PER_INDEX_RESERVATION_FLOOR,
            (int)ceil($max_rows / 50) + 256,
        );

        // ParallelIndexBuilder returns null only on cheap pre-flight
        // bail (FFI/pcntl unavailable) — we can fall back to the
        // serial path safely. Once it commits to mutating the file
        // any failure throws; we let those bubble up because the
        // serial fallback would write on top of orphan index pages
        // and break integrity_check.
        $result = ParallelIndexBuilder::build(
            $db_path,
            $specs,
            $this->defaultWorkerCount(),
            $reservation,
        );
        return $result !== null;
    }

    /**
     * Single source of truth for user index specs that the
     * parallel CREATE INDEX path uses. Mirrors the statements in
     * {@see createIndexes} so both paths produce the same indexes.
     *
     * Each spec also lists the column subset the worker needs to
     * pull from main into its shard. The shard creates a tiny
     * `subset` table with `(rowid INTEGER PRIMARY KEY, ...cols)`
     * preserving main's rowid (so the resulting index's rowid
     * pointers stay valid when relocated back), runs CREATE INDEX
     * on `subset`, and the orchestrator inserts a sqlite_master
     * row with the original SQL — the index storage doesn't
     * reference the table by name, only by rowid, so a query like
     * `SELECT ... FROM context_edges WHERE run_id = ? AND child_node_id = ?`
     * still hits the relocated index correctly.
     *
     * `partial_where` is appended after the column copy so the
     * shard table doesn't have to carry the columns the WHERE
     * clause references but the key doesn't.
     *
     * @return list<array{
     *     name: string,
     *     table: string,
     *     sql: string,
     *     columns: list<string>,
     * }>
     */
    private static function userIndexSpecs(): array
    {
        return [
            [
                'name' => 'idx_context_edges_run_child',
                'table' => 'context_edges',
                'sql' => 'CREATE INDEX idx_context_edges_run_child'
                    . ' ON context_edges(run_id, child_node_id)',
                'columns' => ['run_id', 'child_node_id'],
            ],
            [
                'name' => 'idx_context_node_locations_run_node',
                'table' => 'context_node_locations',
                'sql' => 'CREATE INDEX idx_context_node_locations_run_node'
                    . ' ON context_node_locations(run_id, node_id)',
                'columns' => ['run_id', 'node_id'],
            ],
            [
                'name' => 'idx_context_node_locations_run_class',
                'table' => 'context_node_locations',
                'sql' => 'CREATE INDEX idx_context_node_locations_run_class'
                    . ' ON context_node_locations(run_id, class_name)',
                'columns' => ['run_id', 'class_name'],
            ],
            [
                'name' => 'idx_context_node_attributes_run_node',
                'table' => 'context_node_attributes',
                'sql' => 'CREATE INDEX idx_context_node_attributes_run_node'
                    . ' ON context_node_attributes(run_id, node_id)',
                'columns' => ['run_id', 'node_id'],
            ],
            [
                'name' => 'idx_context_node_locations_run_type',
                'table' => 'context_node_locations',
                'sql' => 'CREATE INDEX idx_context_node_locations_run_type'
                    . ' ON context_node_locations(run_id, location_type)',
                'columns' => ['run_id', 'location_type'],
            ],
            [
                'name' => 'idx_context_node_locations_run_type_size',
                'table' => 'context_node_locations',
                'sql' => 'CREATE INDEX idx_context_node_locations_run_type_size'
                    . ' ON context_node_locations(run_id, location_type, size)',
                'columns' => ['run_id', 'location_type', 'size'],
            ],
            [
                'name' => 'idx_context_edges_run_link_parent',
                'table' => 'context_edges',
                'sql' => 'CREATE INDEX idx_context_edges_run_link_parent'
                    . ' ON context_edges(run_id, link_name, parent_node_id)',
                'columns' => ['run_id', 'link_name', 'parent_node_id'],
            ],
            [
                'name' => 'idx_context_edges_strong_nontree_links',
                'table' => 'context_edges',
                'sql' => 'CREATE INDEX idx_context_edges_strong_nontree_links'
                    . ' ON context_edges(run_id, link_name, child_node_id, parent_node_id)'
                    . " WHERE is_tree = 0 AND strength = 'strong'",
                // Partial WHERE references is_tree + strength, so the
                // shard subset has to carry them too.
                'columns' => [
                    'run_id', 'link_name', 'child_node_id',
                    'parent_node_id', 'is_tree', 'strength',
                ],
            ],
            [
                'name' => 'idx_context_nodes_canonical',
                'table' => 'context_nodes',
                'sql' => 'CREATE INDEX idx_context_nodes_canonical'
                    . ' ON context_nodes(run_id, canonical_node_id)',
                'columns' => ['run_id', 'canonical_node_id'],
            ],
            [
                'name' => 'idx_context_node_locations_region_addr_size',
                'table' => 'context_node_locations',
                'sql' => 'CREATE INDEX idx_context_node_locations_region_addr_size'
                    . ' ON context_node_locations(run_id, region, address, size)',
                'columns' => ['run_id', 'region', 'address', 'size'],
            ],
        ];
    }

    /**
     * Sample the first ~500 rows of each section to estimate average
     * encoded cell size. Used by the plan step to size each worker's
     * leaf-page reservation tightly so the resulting file isn't
     * bloated by safety-margin tail pages.
     *
     * @param array<string, int> $section_counts
     * @return array<string, int>
     * @psalm-suppress InaccessibleMethod
     * @psalm-suppress UndefinedPropertyFetch
     * @psalm-suppress InvalidPropertyFetch
     * @psalm-suppress MixedAssignment
     * @psalm-suppress PossiblyInvalidArrayAccess
     */
    private function estimateAvgCellBytes(
        string $rmem_path,
        array $section_counts,
        int $run_id,
    ): array {
        $reader = BinaryReader::open($rmem_path);
        $dict = $reader->getStringDict();
        $sample_size = 500;
        $factories = [
            'context_nodes' => [$this, 'rowsForNodes'],
            'context_edges' => [$this, 'rowsForEdges'],
            'context_node_locations' => [$this, 'rowsForLocations'],
            'context_node_attributes' => [$this, 'rowsForAttributes'],
        ];
        $out = [];
        foreach ($factories as $name => $factory) {
            $count = $section_counts[$name] ?? 0;
            if ($count === 0) {
                continue;
            }
            $sample = min($sample_size, $count);
            $total_bytes = 0;
            $rows_seen = 0;
            /** @var \Generator<int, array{int, list<int|string|null>}> $rows */
            $rows = $factory($reader, $dict, $run_id, 0, $sample);
            foreach ($rows as [$rowid, $values]) {
                $total_bytes += strlen(
                    \Reli\Inspector\Output\MemoryOutput\SqliteRaw\TableCellEncoder::encodeTableLeafCell(
                        $rowid,
                        $values,
                    )
                );
                $rows_seen++;
            }
            $out[$name] = $rows_seen === 0 ? 100 : (int)ceil($total_bytes / $rows_seen);
        }
        return $out;
    }

    /**
     * Worker entry point for the shared-mmap path: open the rmem,
     * compute this worker's row slice for every section, encode each
     * row as a SQLite cell and stream 4 KiB leaf pages straight into
     * the parent's mmap via {@see SharedMmapTableWriter::workerWriteTable}.
     *
     * @param array<string, int> $section_counts
     * @psalm-suppress InaccessibleMethod
     * @psalm-suppress PossiblyNullPropertyFetch
     * @psalm-suppress UndefinedPropertyFetch
     * @psalm-suppress InvalidPropertyFetch
     * @psalm-suppress MixedAssignment
     * @psalm-suppress MixedArgument
     * @psalm-suppress PossiblyInvalidArrayAccess
     */
    private function workerWriteAllTables(
        \FFI\CData $data_ptr,
        \FFI\CData $meta_ptr,
        SharedMmapPlan $plan,
        string $rmem_path,
        int $worker_index,
        int $worker_count,
        array $section_counts,
        int $run_id,
    ): void {
        $reader = BinaryReader::open($rmem_path);
        $dict = $reader->getStringDict();

        $sections = [
            'context_nodes' => [Format::SECTION_NODES, [$this, 'rowsForNodes']],
            'context_edges' => [Format::SECTION_EDGES, [$this, 'rowsForEdges']],
            'context_node_locations' => [Format::SECTION_LOCATIONS, [$this, 'rowsForLocations']],
            'context_node_attributes' => [Format::SECTION_ATTRIBUTES, [$this, 'rowsForAttributes']],
        ];

        foreach ($sections as $table_name => [$section_id, $rows_factory]) {
            $count = $section_counts[$table_name] ?? 0;
            if ($count === 0) {
                continue;
            }
            [$start, $slice] = $this->computeRowSlice($worker_index, $worker_count, $count);
            if ($slice === 0) {
                continue;
            }
            /** @var \Generator<int, array{int, list<int|string|null>}> $rows */
            $rows = $rows_factory($reader, $dict, $run_id, $start, $slice);
            SharedMmapTableWriter::workerWriteTable(
                data_ptr: $data_ptr,
                meta_ptr: $meta_ptr,
                plan: $plan,
                table_name: $table_name,
                worker_index: $worker_index,
                rows: $rows,
                first_mapped_pgno: 1,
            );
        }
    }

    /**
     * @return \Generator<int, array{int, list<int|string|null>}>
     * @psalm-suppress InaccessibleMethod
     * @psalm-suppress UndefinedPropertyFetch
     * @psalm-suppress InvalidPropertyFetch
     * @psalm-suppress MixedAssignment
     * @psalm-suppress PossiblyInvalidArrayAccess
     */
    private function rowsForNodes(
        BinaryReader $reader,
        \Reli\Inspector\Output\MemoryOutput\BinaryFormat\StringDict $dict,
        int $run_id,
        int $start,
        int $count,
    ): \Generator {
        $end = $start + $count;
        $rows = $reader->castSection(Format::SECTION_NODES, 'NodeRow');
        if ($rows !== null) {
            for ($i = $start; $i < $end; $i++) {
                $type = $dict->lookup($rows[$i]->type_id) ?? '';
                yield [$i + 1, [$run_id, $rows[$i]->node_id, $type, null]];
            }
            return;
        }
        $data = $reader->getSectionData(Format::SECTION_NODES);
        for ($i = $start; $i < $end; $i++) {
            $row = unpack(
                'Vnode_id/Vcanonical_id/Vtype_id',
                $data,
                $i * Format::NODE_ROW_SIZE,
            );
            $type = $dict->lookup((int)$row['type_id']) ?? '';
            yield [$i + 1, [$run_id, (int)$row['node_id'], $type, null]];
        }
    }

    /**
     * @return \Generator<int, array{int, list<int|string|null>}>
     * @psalm-suppress InaccessibleMethod
     * @psalm-suppress UndefinedPropertyFetch
     * @psalm-suppress InvalidPropertyFetch
     * @psalm-suppress MixedAssignment
     * @psalm-suppress PossiblyInvalidArrayAccess
     */
    private function rowsForEdges(
        BinaryReader $reader,
        \Reli\Inspector\Output\MemoryOutput\BinaryFormat\StringDict $dict,
        int $run_id,
        int $start,
        int $count,
    ): \Generator {
        $end = $start + $count;
        $strengths = ['strong', 'weak', 'structural'];
        $rows = $reader->castSection(Format::SECTION_EDGES, 'EdgeRow');
        if ($rows !== null) {
            for ($i = $start; $i < $end; $i++) {
                $parent = $rows[$i]->parent_node_id;
                // First null is the rowid-alias `id` column placeholder
                // — SQLite stores INTEGER PRIMARY KEY columns as a
                // type-0 NULL in the record because the real value
                // lives in the cell's rowid varint.
                yield [$i + 1, [
                    null,
                    $run_id,
                    $parent === Format::NULL_STRING_ID ? null : $parent,
                    $rows[$i]->child_node_id,
                    $dict->lookup($rows[$i]->link_name_id) ?? '',
                    $rows[$i]->is_tree,
                    $strengths[$rows[$i]->strength] ?? 'strong',
                ]];
            }
            return;
        }
        $data = $reader->getSectionData(Format::SECTION_EDGES);
        for ($i = $start; $i < $end; $i++) {
            $row = unpack(
                'Vparent/Vchild/Vlid/Cis_tree/Cstrength',
                $data,
                $i * Format::EDGE_ROW_SIZE,
            );
            $parent = (int)$row['parent'];
            yield [$i + 1, [
                null,
                $run_id,
                $parent === Format::NULL_STRING_ID ? null : $parent,
                (int)$row['child'],
                $dict->lookup((int)$row['lid']) ?? '',
                (int)$row['is_tree'],
                $strengths[(int)$row['strength']] ?? 'strong',
            ]];
        }
    }

    /**
     * @return \Generator<int, array{int, list<int|string|null>}>
     * @psalm-suppress InaccessibleMethod
     * @psalm-suppress UndefinedPropertyFetch
     * @psalm-suppress InvalidPropertyFetch
     * @psalm-suppress MixedAssignment
     * @psalm-suppress PossiblyInvalidArrayAccess
     */
    private function rowsForLocations(
        BinaryReader $reader,
        \Reli\Inspector\Output\MemoryOutput\BinaryFormat\StringDict $dict,
        int $run_id,
        int $start,
        int $count,
    ): \Generator {
        $end = $start + $count;
        $rows = $reader->castSection(Format::SECTION_LOCATIONS, 'LocationRow');
        if ($rows !== null) {
            for ($i = $start; $i < $end; $i++) {
                $class_id = $rows[$i]->class_id;
                $sv_id = $rows[$i]->string_value_id;
                $region_id = $rows[$i]->region_id;
                yield [$i + 1, [
                    null,
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
                ]];
            }
            return;
        }
        $data = $reader->getSectionData(Format::SECTION_LOCATIONS);
        for ($i = $start; $i < $end; $i++) {
            $row = unpack(
                'Vnode_id/Vlocation_type_id/Vclass_id/Paddress/Psize'
                . '/Vstring_value_id/Vrefcount/Vtype_info/Vregion_id/Vbin_overhead',
                $data,
                $i * Format::LOCATION_ROW_SIZE,
            );
            $class_id = (int)$row['class_id'];
            $sv_id = (int)$row['string_value_id'];
            $region_id = (int)$row['region_id'];
            yield [$i + 1, [
                null,
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
            ]];
        }
    }

    /**
     * @return \Generator<int, array{int, list<int|string|null>}>
     * @psalm-suppress InaccessibleMethod
     * @psalm-suppress MixedAssignment
     * @psalm-suppress PossiblyInvalidArrayAccess
     */
    private function rowsForAttributes(
        BinaryReader $reader,
        \Reli\Inspector\Output\MemoryOutput\BinaryFormat\StringDict $dict,
        int $run_id,
        int $start,
        int $count,
    ): \Generator {
        $end = $start + $count;
        $data = $reader->getSectionData(Format::SECTION_ATTRIBUTES);
        $offset = $start * 12;
        for ($i = $start; $i < $end; $i++) {
            $row = unpack('Vnode_id/Vkey_id/Vvalue_id', $data, $offset);
            $offset += 12;
            $key = $dict->lookup((int)$row['key_id']);
            if ($key === null) {
                continue;
            }
            $value_id = (int)$row['value_id'];
            $value = $value_id === Format::NULL_STRING_ID ? null : $dict->lookup($value_id);
            yield [$i + 1, [null, $run_id, (int)$row['node_id'], $key, $value]];
        }
    }

    /**
     * Allocate the run_id (and seed the runs / summary tables) up-
     * front so workers can write canonical run_id values into shard
     * rows without needing a placeholder / UPDATE round-trip.
     *
     * Pre-creates the integer indexes too, but only when L is
     * enabled — at 100K rows on the rbt:analyze trace, the
     * IntegerIndexMerger spends ~0.49s building three integer
     * indexes via PHP-side leaf assembly while the CREATE INDEX
     * machinery in C only takes ~0.28s for the same three. It
     * just isn't a wall-clock win at this scale with the current
     * PHP-only implementation. Off by default until the followups
     * in docs/internals/format-direct-merge.md land. Enable with
     * RELI_FORMAT_DIRECT_INDEX=1.
     *
     * @param array<int, array<string, mixed>> $summary
     */
    private function allocateRun(array $summary): int
    {
        $db = $this->driver->createConnection();
        $db->exec('PRAGMA synchronous = OFF');
        $db->exec('PRAGMA temp_store = MEMORY');
        $this->createTables($db);
        if (self::formatDirectIndexEnabled()) {
            foreach (self::integerIndexSpecs() as [$index_name, $table, $key]) {
                $db->exec("CREATE INDEX IF NOT EXISTS {$index_name} ON {$table}(run_id, {$key})");
            }
        }
        $db->beginTransaction();
        $run_id = $this->insertRun($db);
        $this->insertSummary($db, $run_id, $summary);
        $db->commit();
        return $run_id;
    }

    /**
     * Toggle for the format-direct integer index merge path. See
     * docs/internals/format-direct-merge.md for why it's off by
     * default.
     */
    private static function formatDirectIndexEnabled(): bool
    {
        return getenv('RELI_FORMAT_DIRECT_INDEX') === '1';
    }

    /**
     * Pick a default worker count. Honours `RELI_INGEST_WORKERS` if
     * set, otherwise scales with the available CPU count. The
     * earlier 4-worker cap was a holdover from the table-grain
     * shard merge era — both the shared-mmap data load and the
     * parallel CREATE INDEX path scale with cores beyond 4 (data
     * load is ~linear, parallel CREATE INDEX has ~50% efficiency
     * up to a disk-bound ceiling), so we let users with bigger
     * boxes use them.
     */
    private function defaultWorkerCount(): int
    {
        $env = getenv('RELI_INGEST_WORKERS');
        if (is_string($env) && ctype_digit($env) && (int)$env > 0) {
            return (int)$env;
        }
        $cores = 4;
        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = @file_get_contents('/proc/cpuinfo');
            if (is_string($cpuinfo) && $cpuinfo !== '') {
                $cores = max(1, substr_count($cpuinfo, "\nprocessor\t") + 1);
            }
        }
        return max(1, $cores);
    }

    /**
     * Compute this worker's row range for a section: rows
     * `[i * count / n, (i + 1) * count / n)`. Last worker absorbs
     * the remainder.
     *
     * @return array{int, int} [row_start, row_count]
     */
    private function computeRowSlice(int $worker_index, int $worker_count, int $count): array
    {
        if ($count === 0) {
            return [0, 0];
        }
        $start = intdiv($count * $worker_index, $worker_count);
        $end = $worker_index === $worker_count - 1
            ? $count
            : intdiv($count * ($worker_index + 1), $worker_count);
        return [$start, $end - $start];
    }

    /**
     * @param array<array-key, string> $shard_paths name|index => filesystem path
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
     * Worker entry point: open the shard file, set up all four data
     * tables, and stream this worker's row slice from each rmem
     * section. Runs in a forked child process; throws are caught by
     * the caller and surfaced as a non-zero exit status.
     *
     * @param array<string, int> $section_counts pre-computed element
     *                                           counts for every section,
     *                                           shared by all workers
     */
    private function writeShard(
        string $rmem_path,
        string $shard_path,
        int $worker_index,
        int $worker_count,
        array $section_counts,
        int $run_id,
    ): void {
        $reader = BinaryReader::open($rmem_path);
        $shard = new \PDO("sqlite:{$shard_path}");
        $shard->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $shard->exec('PRAGMA synchronous = OFF');
        $shard->exec('PRAGMA journal_mode = MEMORY');
        $shard->exec('PRAGMA temp_store = MEMORY');

        $this->createShardSchema($shard);

        [$nodes_start, $nodes_count] = $this->computeRowSlice(
            $worker_index,
            $worker_count,
            $section_counts[Format::SECTION_NODES] ?? 0,
        );
        [$edges_start, $edges_count] = $this->computeRowSlice(
            $worker_index,
            $worker_count,
            $section_counts[Format::SECTION_EDGES] ?? 0,
        );
        [$locations_start, $locations_count] = $this->computeRowSlice(
            $worker_index,
            $worker_count,
            $section_counts[Format::SECTION_LOCATIONS] ?? 0,
        );
        [$attrs_start, $attrs_count] = $this->computeRowSlice(
            $worker_index,
            $worker_count,
            $section_counts[Format::SECTION_ATTRIBUTES] ?? 0,
        );

        $shard->beginTransaction();
        try {
            // Use explicit globally-disjoint rowids so the merger can
            // stitch shard table b-trees by appending leaves without
            // re-encoding cells. Worker i's row at section index k
            // gets rowid (k + 1), which combined with non-overlapping
            // (row_start, row_count) windows gives globally
            // contiguous rowids 1..total. The real run_id was
            // allocated by the parent process and passed in, so
            // shard rows are canonical the first time they hit disk.
            $this->ingestNodes($shard, $reader, $run_id, $nodes_start, $nodes_count, true);
            $this->ingestEdges($shard, $reader, $run_id, $edges_start, $edges_count, true);
            $this->ingestLocations(
                $shard,
                $reader,
                $run_id,
                $locations_start,
                $locations_count,
                true,
            );
            $this->ingestAttributes(
                $shard,
                $reader,
                $run_id,
                $attrs_start,
                $attrs_count,
                true,
            );
            $shard->commit();
            // After the table data is in the shard, dump sort-run
            // files for each integer index. These get k-way merged
            // by the main process to skip the SQL CREATE INDEX
            // sort phase entirely.
            $this->dumpIntegerIndexSortRuns($shard, $shard_path);
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
     * Create all four data tables in a shard. Schema matches the main
     * DB's column list (so INSERT INTO main SELECT * is a 1:1 copy)
     * but omits indexes, primary keys, and id surrogates — those
     * would slow the worker's inserts and the main table rebuilds
     * them anyway.
     */
    /**
     * Shard schema must match main's column *order* exactly, because
     * the format-direct merger copies leaf cell bytes verbatim — the
     * record encoding is positional. In particular the surrogate
     * `id INTEGER PRIMARY KEY` columns on `context_edges` /
     * `context_node_locations` / `context_node_attributes` have to
     * appear in the same slot here so that the worker's
     * `INSERT INTO ... (rowid, ...)` gets stored with the
     * INTEGER-PRIMARY-KEY-alias semantics SQLite expects.
     */
    private function createShardSchema(\PDO $shard): void
    {
        $shard->exec(
            'CREATE TABLE context_nodes ('
            . ' run_id INTEGER NOT NULL,'
            . ' node_id INTEGER NOT NULL,'
            . ' type TEXT NOT NULL,'
            . ' canonical_node_id INTEGER'
            . ')'
        );
        $shard->exec(
            'CREATE TABLE context_edges ('
            . ' id INTEGER PRIMARY KEY,'
            . ' run_id INTEGER NOT NULL,'
            . ' parent_node_id INTEGER,'
            . ' child_node_id INTEGER NOT NULL,'
            . ' link_name TEXT NOT NULL,'
            . ' is_tree INTEGER NOT NULL,'
            . " strength TEXT NOT NULL DEFAULT 'strong'"
            . ')'
        );
        $shard->exec(
            'CREATE TABLE context_node_locations ('
            . ' id INTEGER PRIMARY KEY,'
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
        $shard->exec(
            'CREATE TABLE context_node_attributes ('
            . ' id INTEGER PRIMARY KEY,'
            . ' run_id INTEGER NOT NULL,'
            . ' node_id INTEGER NOT NULL,'
            . ' "key" TEXT NOT NULL,'
            . ' "value" TEXT'
            . ')'
        );
    }

    /**
     * Open the target DB, create the full schema, ATTACH each shard,
     * and copy its rows over while rewriting the run_id placeholder to
     * the freshly-allocated run_id. After the copy, build the derived
     * summaries, canonical-id map, indexes, and views as in the
     * single-process path.
     *
     * Each shard now contains all four data tables, holding this
     * worker's slice of the corresponding rmem section. The merge
     * walks shards in order so the rowid append on the main tables
     * preserves the section's emission order across the whole
     * dataset — important for the format-direct merge that's meant
     * to replace this loop.
     *
     * @param array<int, string> $shard_paths shard index => filesystem path
     * @param array<int, array<string, mixed>> $summary
     */
    private function mergeShards(array $shard_paths, int $run_id): void
    {
        if (!$this->driver instanceof SqliteDriver) {
            throw new \RuntimeException('format-direct merge only supports SqliteDriver');
        }
        $main_path = $this->driver->path();
        $tables = ['context_nodes', 'context_edges', 'context_node_locations', 'context_node_attributes'];

        $needs_sql_fallback = false;
        try {
            $writer = SqliteRawWriter::open($main_path);
            $shard_readers = array_map(
                static fn (string $p): SqliteRawReader => SqliteRawReader::open($p),
                array_values($shard_paths),
            );
            $merger = new TableMerger($writer, $shard_readers);
            foreach ($tables as $table) {
                $rootpage = SqliteRawReader::open($main_path)->tableRootpage($table);
                $merger->merge($table, $rootpage);
            }
            $writer->close($main_path);
        } catch (OverflowNotSupportedException $_e) {
            // Some leaf cell exceeded the inline payload threshold
            // (typically a long string_value). Fall back to the SQL
            // merge path. The pages the format-direct merger touched
            // are harmless because the SQL fallback opens main fresh
            // and re-INSERTs every row.
            $needs_sql_fallback = true;
        }

        $db = $this->driver->createConnection();
        $db->exec('PRAGMA synchronous = OFF');
        $db->exec('PRAGMA temp_store = MEMORY');

        if (!$needs_sql_fallback) {
            // Format-direct table copy bypasses the b-tree machinery,
            // so the auto-indexes that the PRIMARY KEYs maintain are
            // still the empty leaves they were at CREATE TABLE time.
            // REINDEX rebuilds them from the freshly-merged table
            // data. Workers already wrote canonical run_id values,
            // so no UPDATE round-trip is needed.
            $db->exec('REINDEX context_nodes');
            $db->exec('REINDEX summary');
            $db->exec('REINDEX location_types_summary');
            $db->exec('REINDEX class_objects_summary');
        }

        if ($needs_sql_fallback) {
            // Fallback: ATTACH each shard and INSERT INTO main FROM
            // it. Same code path the pre-format-direct implementation
            // used.
            foreach ($shard_paths as $idx => $path) {
                $db->exec("ATTACH DATABASE '{$path}' AS s_{$idx}");
            }
            $db->beginTransaction();
            foreach (array_keys($shard_paths) as $idx) {
                $db->exec(
                    "INSERT INTO context_nodes (run_id, node_id, type, canonical_node_id)"
                    . " SELECT {$run_id}, node_id, type, canonical_node_id FROM s_{$idx}.context_nodes"
                );
                $db->exec(
                    "INSERT INTO context_edges (run_id, parent_node_id, child_node_id, link_name, is_tree, strength)"
                    . " SELECT {$run_id}, parent_node_id, child_node_id, link_name, is_tree, strength"
                    . " FROM s_{$idx}.context_edges"
                );
                $db->exec(
                    "INSERT INTO context_node_locations"
                    . " (run_id, node_id, address, size, location_type, class_name, string_value,"
                    . "  refcount, type_info, region, bin_overhead)"
                    . " SELECT {$run_id}, node_id, address, size, location_type, class_name, string_value,"
                    . "  refcount, type_info, region, bin_overhead"
                    . " FROM s_{$idx}.context_node_locations"
                );
                $db->exec(
                    "INSERT INTO context_node_attributes (run_id, node_id, \"key\", \"value\")"
                    . " SELECT {$run_id}, node_id, \"key\", \"value\" FROM s_{$idx}.context_node_attributes"
                );
            }
            $db->commit();
        }

        // Format-direct integer index merge (gated, off by default).
        // When enabled, has to run BEFORE the summary inserts:
        // the integer indexes' rootpages were pre-allocated but
        // currently hold an empty leaf, and SQLite's planner
        // happily uses them for queries like `SELECT ... WHERE
        // run_id = ?`, which then returns zero rows. Populating
        // the indexes first means subsequent SELECTs that route
        // through them see the real data.
        if (!$needs_sql_fallback && self::formatDirectIndexEnabled()) {
            unset($db);
            $this->mergeIntegerIndexes($shard_paths, $main_path, $run_id);
            $db = $this->driver->createConnection();
            $db->exec('PRAGMA synchronous = OFF');
            $db->exec('PRAGMA temp_store = MEMORY');
        }

        $db->beginTransaction();
        $this->insertLocationTypesSummaryFromDb($db, $run_id);
        $this->insertClassObjectsSummaryFromDb($db, $run_id);
        $this->computeCanonicalNodeIds($db, $run_id);
        $db->commit();

        $this->driver->afterBulkInsert($db);

        // The remaining indexes (text-keyed, partial, post-merge-
        // derived like idx_context_nodes_canonical) get built the
        // normal way. CREATE INDEX IF NOT EXISTS on the integer
        // indexes is a no-op since they already exist.
        $this->createIndexes($db);
        $this->createViews($db);
    }

    /**
     * The integer-only indexes whose b-tree we build via
     * IntegerIndexMerger instead of letting SQLite's CREATE INDEX
     * machinery sort + write them. Each entry is
     *   [index_name, table_name, key_column].
     * `run_id` is implicit (always the merged DB's run_id) and
     * `rowid` is the index's tiebreaker. The remaining indexes
     * (text-keyed, partial, post-merge-derived like
     * idx_context_nodes_canonical) still go through createIndexes.
     *
     * @return list<array{string, string, string}>
     */
    private static function integerIndexSpecs(): array
    {
        return [
            ['idx_context_node_locations_run_node', 'context_node_locations', 'node_id'],
            ['idx_context_node_attributes_run_node', 'context_node_attributes', 'node_id'],
            ['idx_context_edges_run_child', 'context_edges', 'child_node_id'],
        ];
    }

    /**
     * Sort-run filename convention. Each shard gets one sort-run file
     * per integer index, sitting alongside the shard SQLite file so
     * cleanup is one big rm at the end of the merge.
     */
    private static function sortRunPath(string $shard_path, string $index_name): string
    {
        return $shard_path . '.' . $index_name . '.run';
    }

    /**
     * Worker side: stream each integer index's sort run out of the
     * shard SQLite into a binary file the main process will k-way
     * merge. We let SQLite do the per-shard sort because (a) the
     * shard is already open and (b) the sort cost scales with shard
     * size, not total size, so it parallelises across workers.
     *
     * @psalm-suppress MixedAssignment
     */
    private function dumpIntegerIndexSortRuns(\PDO $shard, string $shard_path): void
    {
        foreach (self::integerIndexSpecs() as [$index_name, $table, $key_column]) {
            $writer = new SortRunWriter(self::sortRunPath($shard_path, $index_name));
            $stmt = $shard->prepare(
                "SELECT {$key_column}, rowid FROM {$table} ORDER BY {$key_column}, rowid"
            );
            $stmt->execute();
            while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
                /** @psalm-suppress MixedArrayAccess */
                $writer->append((int)$row[0], (int)$row[1]);
            }
            $writer->close();
        }
    }

    /**
     * Main side: for each integer index, k-way merge the per-shard
     * sort runs and write the resulting b-tree into the index's
     * already-allocated rootpage.
     *
     * Pre-conditions:
     *   - main has CREATE INDEX'd these indexes already so the
     *     rootpages exist (each pointing at an empty leaf)
     *   - main is closed (PDO connection unset) so the writer can
     *     do raw page I/O
     *
     * @param array<int, string> $shard_paths shard index => path
     */
    private function mergeIntegerIndexes(
        array $shard_paths,
        string $main_path,
        int $run_id,
    ): void {
        foreach (self::integerIndexSpecs() as [$index_name, $_table, $_key]) {
            $reader = SqliteRawReader::open($main_path);
            $rootpage = $reader->findSchemaEntry($index_name);
            if ($rootpage === null) {
                // Index wasn't created — caller bug. Skip rather than
                // throw, so an unexpected schema doesn't prevent the
                // rest of the merge from completing.
                continue;
            }
            $sort_runs = [];
            foreach ($shard_paths as $shard_path) {
                $run_path = self::sortRunPath($shard_path, $index_name);
                if (file_exists($run_path)) {
                    $sort_runs[] = new SortRunReader($run_path);
                }
            }
            if ($sort_runs === []) {
                continue;
            }
            $writer = SqliteRawWriter::open($main_path);
            $merger = new IntegerIndexMerger($writer, $sort_runs, $run_id);
            try {
                $merger->merge($rootpage['rootpage']);
            } catch (OverflowNotSupportedException $_e) {
                // Should not happen for our 3-int payloads; if it
                // does, leave SQLite's CREATE INDEX (run by
                // createIndexes after this) build the index in the
                // normal way.
                continue;
            }
            $writer->close($main_path);
        }
    }

    /**
     * Cleanup all sort-run files alongside the given shards. Called
     * unconditionally at the end of the merge so failures don't leak
     * gigabytes of temp data.
     *
     * @param array<int, string> $shard_paths
     */
    private function cleanupSortRuns(array $shard_paths): void
    {
        foreach ($shard_paths as $shard_path) {
            foreach (self::integerIndexSpecs() as [$index_name, $_table, $_key]) {
                $run_path = self::sortRunPath($shard_path, $index_name);
                if (file_exists($run_path)) {
                    @unlink($run_path);
                }
            }
        }
    }

    /**
     * Workers wrote shards with run_id = 0 (placeholder) so the
     * format-direct copy didn't have to rewrite per-cell varints.
     * Fix it up with a single UPDATE per table — cheap because the
     * affected rows are exactly the ones whose run_id is currently 0,
     * and there are no indexes to maintain yet.
     */
    private function rewriteWorkerRunId(\PDO $db, int $run_id): void
    {
        if ($run_id === 0) {
            return;
        }
        $db->beginTransaction();
        $db->exec("UPDATE context_nodes SET run_id = {$run_id} WHERE run_id = 0");
        $db->exec("UPDATE context_edges SET run_id = {$run_id} WHERE run_id = 0");
        $db->exec("UPDATE context_node_locations SET run_id = {$run_id} WHERE run_id = 0");
        $db->exec("UPDATE context_node_attributes SET run_id = {$run_id} WHERE run_id = 0");
        $db->commit();
    }

    /**
     * @psalm-suppress InaccessibleMethod
     * @psalm-suppress PossiblyNullPropertyFetch
     * @psalm-suppress UndefinedPropertyFetch
     * @psalm-suppress InvalidPropertyFetch
     * @psalm-suppress MixedAssignment
     * @psalm-suppress PossiblyInvalidArrayAccess
     */
    private function ingestNodes(
        \PDO $db,
        BinaryReader $reader,
        int $run_id,
        int $row_start = 0,
        ?int $row_count = null,
        bool $explicit_rowid = false,
    ): void {
        if (!$reader->hasSection(Format::SECTION_NODES)) {
            return;
        }
        $dict = $reader->getStringDict();
        $total = $reader->getSectionElementCount(Format::SECTION_NODES);
        $end = $row_count === null ? $total : min($total, $row_start + $row_count);
        if ($row_start >= $end) {
            return;
        }
        $sql = $explicit_rowid
            ? 'INSERT INTO context_nodes (rowid, run_id, node_id, type, canonical_node_id)'
                . ' VALUES (?, ?, ?, ?, NULL)'
            : 'INSERT INTO context_nodes (run_id, node_id, type, canonical_node_id) VALUES (?, ?, ?, NULL)';
        $insert = $db->prepare($sql);
        $rows = $reader->castSection(Format::SECTION_NODES, 'NodeRow');
        if ($rows !== null) {
            for ($i = $row_start; $i < $end; $i++) {
                $type = $dict->lookup($rows[$i]->type_id) ?? '';
                $args = $explicit_rowid
                    ? [$i + 1, $run_id, $rows[$i]->node_id, $type]
                    : [$run_id, $rows[$i]->node_id, $type];
                $insert->execute($args);
            }
            return;
        }
        $data = $reader->getSectionData(Format::SECTION_NODES);
        for ($i = $row_start; $i < $end; $i++) {
            $off = $i * Format::NODE_ROW_SIZE;
            $row = unpack('Vnode_id/Vcanonical_id/Vtype_id', $data, $off);
            $type = $dict->lookup((int)$row['type_id']) ?? '';
            $args = $explicit_rowid
                ? [$i + 1, $run_id, (int)$row['node_id'], $type]
                : [$run_id, (int)$row['node_id'], $type];
            $insert->execute($args);
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
    private function ingestEdges(
        \PDO $db,
        BinaryReader $reader,
        int $run_id,
        int $row_start = 0,
        ?int $row_count = null,
        bool $explicit_rowid = false,
    ): void {
        if (!$reader->hasSection(Format::SECTION_EDGES)) {
            return;
        }
        $dict = $reader->getStringDict();
        $total = $reader->getSectionElementCount(Format::SECTION_EDGES);
        $end = $row_count === null ? $total : min($total, $row_start + $row_count);
        if ($row_start >= $end) {
            return;
        }
        $sql = $explicit_rowid
            ? 'INSERT INTO context_edges'
                . ' (rowid, run_id, parent_node_id, child_node_id, link_name, is_tree, strength)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
            : 'INSERT INTO context_edges (run_id, parent_node_id, child_node_id, link_name, is_tree, strength)'
                . ' VALUES (?, ?, ?, ?, ?, ?)';
        $insert = $db->prepare($sql);
        $strengths = ['strong', 'weak', 'structural'];
        $rows = $reader->castSection(Format::SECTION_EDGES, 'EdgeRow');
        if ($rows !== null) {
            for ($i = $row_start; $i < $end; $i++) {
                $parent = $rows[$i]->parent_node_id;
                $values = [
                    $run_id,
                    $parent === Format::NULL_STRING_ID ? null : $parent,
                    $rows[$i]->child_node_id,
                    $dict->lookup($rows[$i]->link_name_id) ?? '',
                    $rows[$i]->is_tree,
                    $strengths[$rows[$i]->strength] ?? 'strong',
                ];
                $insert->execute($explicit_rowid ? array_merge([$i + 1], $values) : $values);
            }
            return;
        }
        $data = $reader->getSectionData(Format::SECTION_EDGES);
        for ($i = $row_start; $i < $end; $i++) {
            $off = $i * Format::EDGE_ROW_SIZE;
            $row = unpack('Vparent/Vchild/Vlid/Cis_tree/Cstrength', $data, $off);
            $parent = (int)$row['parent'];
            $values = [
                $run_id,
                $parent === Format::NULL_STRING_ID ? null : $parent,
                (int)$row['child'],
                $dict->lookup((int)$row['lid']) ?? '',
                (int)$row['is_tree'],
                $strengths[(int)$row['strength']] ?? 'strong',
            ];
            $insert->execute($explicit_rowid ? array_merge([$i + 1], $values) : $values);
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
    private function ingestLocations(
        \PDO $db,
        BinaryReader $reader,
        int $run_id,
        int $row_start = 0,
        ?int $row_count = null,
        bool $explicit_rowid = false,
    ): void {
        if (!$reader->hasSection(Format::SECTION_LOCATIONS)) {
            return;
        }
        $dict = $reader->getStringDict();
        $total = $reader->getSectionElementCount(Format::SECTION_LOCATIONS);
        $end = $row_count === null ? $total : min($total, $row_start + $row_count);
        if ($row_start >= $end) {
            return;
        }
        $sql = $explicit_rowid
            ? 'INSERT INTO context_node_locations'
                . ' (rowid, run_id, node_id, address, size, location_type, class_name, string_value,'
                . '  refcount, type_info, region, bin_overhead)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            : 'INSERT INTO context_node_locations'
                . ' (run_id, node_id, address, size, location_type, class_name, string_value,'
                . '  refcount, type_info, region, bin_overhead)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $insert = $db->prepare($sql);
        $rows = $reader->castSection(Format::SECTION_LOCATIONS, 'LocationRow');
        if ($rows !== null) {
            for ($i = $row_start; $i < $end; $i++) {
                $class_id = $rows[$i]->class_id;
                $sv_id = $rows[$i]->string_value_id;
                $region_id = $rows[$i]->region_id;
                $values = [
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
                ];
                $insert->execute($explicit_rowid ? array_merge([$i + 1], $values) : $values);
            }
            return;
        }
        $data = $reader->getSectionData(Format::SECTION_LOCATIONS);
        for ($i = $row_start; $i < $end; $i++) {
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
            $values = [
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
            ];
            $insert->execute($explicit_rowid ? array_merge([$i + 1], $values) : $values);
        }
    }

    /**
     * @psalm-suppress MixedAssignment
     * @psalm-suppress PossiblyInvalidArrayAccess
     */
    private function ingestAttributes(
        \PDO $db,
        BinaryReader $reader,
        int $run_id,
        int $row_start = 0,
        ?int $row_count = null,
        bool $explicit_rowid = false,
    ): void {
        if (!$reader->hasSection(Format::SECTION_ATTRIBUTES)) {
            return;
        }
        $total = $reader->getSectionElementCount(Format::SECTION_ATTRIBUTES);
        $end = $row_count === null ? $total : min($total, $row_start + $row_count);
        if ($row_start >= $end) {
            return;
        }
        $dict = $reader->getStringDict();
        $qi = fn (string $id): string => $this->driver->quoteIdentifier($id);
        $sql = $explicit_rowid
            ? "INSERT INTO context_node_attributes (rowid, run_id, node_id, {$qi('key')}, {$qi('value')})"
                . ' VALUES (?, ?, ?, ?, ?)'
            : "INSERT INTO context_node_attributes (run_id, node_id, {$qi('key')}, {$qi('value')})"
                . ' VALUES (?, ?, ?, ?)';
        $insert = $db->prepare($sql);
        $data = $reader->getSectionData(Format::SECTION_ATTRIBUTES);
        $offset = $row_start * 12;
        for ($i = $row_start; $i < $end; $i++) {
            $row = unpack('Vnode_id/Vkey_id/Vvalue_id', $data, $offset);
            $offset += 12;
            $key = $dict->lookup((int)$row['key_id']);
            if ($key === null) {
                continue;
            }
            $value_id = (int)$row['value_id'];
            $value = $value_id === Format::NULL_STRING_ID ? null : $dict->lookup($value_id);
            $values = [$run_id, (int)$row['node_id'], $key, $value];
            $insert->execute($explicit_rowid ? array_merge([$i + 1], $values) : $values);
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

        // On SQLite, `id INTEGER PRIMARY KEY` is the rowid alias on a
        // regular ROWID table — no extra storage cost, but it gives
        // report-time chunked loaders a stable, indexable column they
        // can paginate by (`WHERE run_id = ? AND id > ? LIMIT N`)
        // without SQLite's planner mistakenly using the (run_id, *)
        // covering indexes and post-filtering rowid per chunk. Use
        // `{$autoId}` so PostgreSQL/MySQL get a SERIAL/AUTO_INCREMENT
        // default — INSERTs below omit `id` and the literal would
        // leave them with a NOT NULL violation.
        $db->exec("
            CREATE TABLE IF NOT EXISTS context_edges (
                id {$autoId},
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
            // Multi-threaded sort during CREATE INDEX. Modest win
            // (~7% on createIndexes wall-clock at 500K rows on this
            // box) — the bulk of CREATE INDEX is the sort phase, and
            // SQLite splits it across worker threads when threads>0.
            // The b-tree write phase is still single-threaded under
            // the file's write lock.
            $db->exec('PRAGMA threads = 4');
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
}
