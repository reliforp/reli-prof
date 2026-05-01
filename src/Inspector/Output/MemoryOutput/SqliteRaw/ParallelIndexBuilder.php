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

namespace Reli\Inspector\Output\MemoryOutput\SqliteRaw;

use Reli\Lib\File\LibcFileWriter;

/**
 * Parallel CREATE INDEX via per-worker shard files + native page
 * relocation back to the main DB.
 *
 * The lock-free part (each worker has its own file) lets all cores
 * actually run SQLite's native CREATE INDEX in parallel — multi-PDO
 * CREATE INDEX against the same file is *slower* than serial
 * because of file write-lock contention. The merge part uses
 * {@see IndexBtreeExtractor} so we don't pay the PHP-side leaf-
 * assembly cost that made an earlier sort-run-merge attempt lose to
 * SQLite's C implementation.
 *
 * Workflow:
 *   1. Reserve a generous pgno range in main per (worker, index).
 *      The data file is sparse — `ftruncate` to the upper bound is
 *      effectively free and we recover unused tails afterwards.
 *   2. mmap main (shared) before fork so workers inherit the
 *      mapping.
 *   3. Each worker:
 *        - cp main_db → shard_w
 *        - PDO open shard_w; CREATE INDEX for its assigned subset
 *        - close PDO so SQLite flushes its journal
 *        - reopen shard_w as a binary fp; for each new index look
 *          up its rootpage in the shard's sqlite_master, walk the
 *          b-tree via IndexBtreeExtractor with the worker's
 *          assigned dest range as the relocation base, memcpy the
 *          relocated pages into the inherited mmap of main
 *        - record (root_pgno, page_count) for each index in a
 *          shared meta region
 *        - unlink shard_w; exit
 *   4. Main: read the meta region, INSERT a sqlite_master row per
 *      index via PRAGMA writable_schema, return the unused-pgno
 *      list so the caller can hand it to {@see SqliteFileMaintainer::finalize}.
 *
 * Falls back to the caller's serial path when fork or FFI is
 * unavailable (returns null from {@see build}).
 *
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedArgument
 * @psalm-suppress UnusedFunctionCall
 */
final class ParallelIndexBuilder
{
    public const int PAGE_SIZE = 4096;

    /**
     * Floor for the per-index page reservation. Sparse file so the
     * upper bound mostly affects offset arithmetic, but mid-file
     * unused pages can't be reclaimed without VACUUM so we want
     * the reservation to track real index size. The orchestrator
     * passes a `pages_per_index_reservation` value derived from
     * row count and column shape; this is the minimum.
     */
    public const int PAGES_PER_INDEX_RESERVATION_FLOOR = 1024;

    /** Bytes the worker meta region uses per (worker, index) slot. */
    private const int META_BYTES_PER_SLOT = 16;

    /**
     * Build the supplied indexes in parallel and return the list of
     * unused pgnos for the caller to put on the freelist, plus the
     * final effective page count of the file. Returns null when
     * fork or FFI is unavailable or any worker fails.
     *
     * `$index_specs` is a list of associative arrays:
     *   `name`      — index name
     *   `table`     — source table name in main
     *   `sql`       — original CREATE INDEX statement (verbatim, used
     *                 for the sqlite_master row inserted at the end)
     *   `columns`   — list of source-table columns the index plus its
     *                 partial WHERE need; the worker creates a tiny
     *                 `subset` table in its shard with these columns
     *                 (rowid preserved from main) and runs the index
     *                 build against that, instead of cp-ing the whole
     *                 main DB. ~10-30x less I/O than full cp on real
     *                 schemas.
     *
     * @param list<array{name: string, table: string, sql: string, columns: list<string>}> $index_specs
     * @return array{int}|null [effective_total_pages]
     */
    public static function build(
        string $main_db_path,
        array $index_specs,
        int $worker_count,
        int $pages_per_index_reservation = self::PAGES_PER_INDEX_RESERVATION_FLOOR,
    ): ?array {
        if (
            !extension_loaded('ffi')
            || !function_exists('pcntl_fork')
            || !function_exists('pcntl_waitpid')
        ) {
            return null;
        }
        if ($index_specs === []) {
            return [0];
        }
        $worker_count = max(1, min($worker_count, count($index_specs)));

        // Bucket indexes round-robin across workers.
        $buckets = array_fill(0, $worker_count, []);
        foreach ($index_specs as $i => $spec) {
            $buckets[$i % $worker_count][] = [$i, $spec];
        }

        $current_pages = self::pageCount($main_db_path);
        if ($current_pages === null) {
            return null;
        }
        $reservation_per_idx = max(
            self::PAGES_PER_INDEX_RESERVATION_FLOOR,
            $pages_per_index_reservation,
        );
        // Generous upper bound for the initial ftruncate. Workers
        // claim from a shared atomic counter, so they only ever
        // touch dense pgno ranges; the hard upper bound here is
        // for mmap-able address space and gets ftruncate'd back
        // down to the actual highest claimed pgno at the end.
        $upper_bound_pages = $current_pages + count($index_specs) * $reservation_per_idx;

        // Pre-grow main DB (sparse).
        $libc = \FFI::cdef('int open(const char *, int, int);', null);
        /** @var int $fd */
        $fd = $libc->open($main_db_path, 2, 0);
        if ($fd < 0) {
            return null;
        }
        if (!LibcFileWriter::ftruncate($fd, $upper_bound_pages * self::PAGE_SIZE)) {
            LibcFileWriter::close($fd);
            return null;
        }
        LibcFileWriter::close($fd);

        // Meta region: per global-index slot of META_BYTES_PER_SLOT
        // bytes carrying [root_pgno (4 BE)][page_count (4 BE)]
        // [reserved (8)].
        $meta_path = $main_db_path . '.pib_meta';
        $meta_size = count($index_specs) * self::META_BYTES_PER_SLOT;
        file_put_contents($meta_path, str_repeat("\x00", $meta_size));

        // Shared atomic pgno counter — workers claim exact-sized
        // ranges from this after CREATE INDEX runs and they know
        // each index's actual page count. Initialised to the
        // first pgno past the populated tables / autoindexes.
        $counter_path = $main_db_path . '.pib_counter';
        SharedPgnoCounter::init($counter_path, $current_pages + 1);

        $data_res = LibcFileWriter::mmapShared($main_db_path, $upper_bound_pages * self::PAGE_SIZE);
        $meta_res = LibcFileWriter::mmapShared($meta_path, $meta_size);
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
            @unlink($counter_path);
            return null;
        }
        [$data_ptr, $data_len, $data_fd] = $data_res;
        [$meta_ptr, $meta_len, $meta_fd] = $meta_res;

        $any_failure = false;
        try {
            $pids = [];
            $shard_paths = [];
            for ($w = 0; $w < $worker_count; $w++) {
                $shard_paths[$w] = $main_db_path . '.pib_shard_' . $w;
                $pid = pcntl_fork();
                if ($pid === -1) {
                    foreach ($pids as $running) {
                        posix_kill($running, SIGTERM);
                        pcntl_waitpid($running, $_status);
                    }
                    $any_failure = true;
                    break;
                }
                if ($pid === 0) {
                    try {
                        self::runWorker(
                            $main_db_path,
                            $shard_paths[$w],
                            $buckets[$w],
                            $counter_path,
                            $data_ptr,
                            $meta_ptr,
                        );
                        exit(0);
                    } catch (\Throwable $e) {
                        fwrite(STDERR, "ParallelIndexBuilder worker {$w}: " . $e->getMessage() . "\n");
                        exit(1);
                    }
                }
                $pids[] = $pid;
            }
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
                $code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 1;
                if ($code !== 0) {
                    $any_failure = true;
                }
            }
            foreach ($shard_paths as $sp) {
                @unlink($sp);
            }
            if ($any_failure) {
                return null;
            }

            // Read meta region back: for each index, the actual
            // root pgno workers claimed and the page count they
            // wrote there. The shared atomic counter guarantees
            // claims are dense, so the file ends exactly at the
            // counter's final value with no mid-file holes.
            $index_results = [];
            foreach ($index_specs as $i => $spec) {
                $offset = $i * self::META_BYTES_PER_SLOT;
                $bytes = self::readMetaSlot($meta_ptr, $offset);
                /** @var array{root: int, count: int} $u */
                $u = unpack('Nroot/Ncount', $bytes);
                $root = $u['root'];
                $page_count = $u['count'];
                if ($root === 0 || $page_count === 0) {
                    return null;
                }
                $index_results[$spec['name']] = [
                    'table' => $spec['table'],
                    'sql' => $spec['sql'],
                    'root' => $root,
                ];
            }

            $effective_total_pages = SharedPgnoCounter::peek($counter_path) - 1;

            LibcFileWriter::msync($data_ptr, $data_len);
        } finally {
            LibcFileWriter::munmap($data_ptr, $data_len);
            LibcFileWriter::munmap($meta_ptr, $meta_len);
            LibcFileWriter::close($meta_fd);
            // Truncate the data file down to the counter's final
            // value — must happen after munmap so the shrink doesn't
            // race with the still-mapped region.
            if (
                isset($effective_total_pages)
                && $effective_total_pages < $upper_bound_pages
            ) {
                LibcFileWriter::ftruncate($data_fd, $effective_total_pages * self::PAGE_SIZE);
            }
            LibcFileWriter::close($data_fd);
            @unlink($meta_path);
            @unlink($counter_path);
        }

        // The early `return null` on worker failure already happens
        // before we get here via the wait loop, but keeping the
        // guard makes the contract explicit.
        /** @psalm-suppress TypeDoesNotContainType */
        if ($any_failure) {
            return null;
        }

        // Update the SQLite file header so a freshly-opened
        // connection sees the extended page count. Without this
        // the in-header page_count from the prior phase is stale
        // and SQLite reports our index rootpages as invalid.
        SqliteFileMaintainer::updateFileHeaderViaFp($main_db_path, $effective_total_pages);

        // Insert sqlite_master rows for each new index via
        // writable_schema. The rootpages already point at the
        // relocated pages we wrote during the fork phase.
        $db = new \PDO("sqlite:{$main_db_path}");
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA writable_schema = ON');
        $stmt = $db->prepare(
            'INSERT INTO sqlite_master (type, name, tbl_name, rootpage, sql)'
            . ' VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($index_results as $name => $r) {
            $stmt->execute(['index', $name, $r['table'], $r['root'], $r['sql']]);
        }
        $db->exec('PRAGMA writable_schema = OFF');
        unset($stmt, $db);

        return [$effective_total_pages];
    }

    /**
     * @param list<array{int, array{name: string, table: string, sql: string, columns: list<string>}}> $bucket
     */
    private static function runWorker(
        string $main_db_path,
        string $shard_path,
        array $bucket,
        string $counter_path,
        \FFI\CData $data_ptr,
        \FFI\CData $meta_ptr,
    ): void {
        // Empty shard. ATTACH main read-only and INSERT just the
        // column subset each assigned index needs, preserving main's
        // rowid so the index's rowid pointers stay valid when its
        // pages get relocated back.
        $db = new \PDO("sqlite:{$shard_path}");
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA synchronous = OFF');
        $db->exec('PRAGMA journal_mode = MEMORY');
        $db->exec('PRAGMA temp_store = MEMORY');
        $db->exec('PRAGMA cache_size = -262144');
        $db->exec('PRAGMA threads = 2');
        $db->exec("ATTACH DATABASE " . $db->quote($main_db_path) . " AS m");

        // Group assigned specs by source table so a worker that
        // owns multiple indexes on the same table only carries one
        // shard subset table (with the union of needed columns).
        /** @var array<string, array{cols: array<string, true>, specs: list<array{int, array{name: string, table: string, sql: string, columns: list<string>}}>}> $by_table */
        $by_table = [];
        foreach ($bucket as $entry) {
            [$_global_idx, $spec] = $entry;
            $tbl = $spec['table'];
            if (!isset($by_table[$tbl])) {
                $by_table[$tbl] = ['cols' => [], 'specs' => []];
            }
            foreach ($spec['columns'] as $c) {
                $by_table[$tbl]['cols'][$c] = true;
            }
            $by_table[$tbl]['specs'][] = $entry;
        }

        $qi = static fn (string $id): string => '"' . str_replace('"', '""', $id) . '"';

        // For each table group: CREATE TABLE shard_<tbl> with rowid
        // preserved from main, INSERT the column subset, then loop
        // over its assigned indexes building each on the subset.
        $shard_roots = [];
        foreach ($by_table as $tbl => $group) {
            $cols = array_keys($group['cols']);
            $col_list = implode(', ', array_map($qi, $cols));
            $col_decls = implode(', ', array_map(
                static fn (string $c): string => $qi($c),
                $cols,
            ));
            $shard_table = $tbl;
            // Create the shard table with the SAME name as main's
            // so the original CREATE INDEX SQL applies verbatim.
            // rowid is the SQLite default; main's INTEGER PRIMARY
            // KEY tables expose `id` as the rowid alias, the others
            // (context_nodes) use the implicit rowid.
            $id_select = self::sourceRowIdExpression($tbl);
            $db->exec(
                "CREATE TABLE {$qi($shard_table)} ("
                . "rowid INTEGER PRIMARY KEY, {$col_decls}"
                . ")"
            );
            $db->exec(
                "INSERT INTO {$qi($shard_table)} (rowid, {$col_list})"
                . " SELECT {$id_select}, {$col_list} FROM m.{$qi($tbl)}"
            );

            foreach ($group['specs'] as [$global_idx, $spec]) {
                $db->exec($spec['sql']);
                $shard_roots[$global_idx] = (int)$db->query(
                    'SELECT rootpage FROM sqlite_master WHERE name = '
                    . $db->quote($spec['name'])
                )->fetchColumn();
                if ($shard_roots[$global_idx] <= 0) {
                    throw new \RuntimeException(
                        "CREATE INDEX produced no rootpage for {$spec['name']}"
                    );
                }
            }
        }
        // Close PDO so the shard file is consistent before we read
        // it back as raw bytes.
        unset($db);

        // Phase 2: open shard as bytes; for each index measure its
        // page count, atomically claim that many pgnos from the
        // shared counter, extract+relocate at the claimed range,
        // memcpy into main's mmap. Per-index claims are dense, so
        // the destination has no mid-file gaps between indexes.
        $fp = fopen($shard_path, 'rb');
        if ($fp === false) {
            throw new \RuntimeException("failed to open shard {$shard_path}");
        }
        try {
            foreach ($bucket as [$global_idx, $_spec]) {
                $page_count = IndexBtreeExtractor::countPages(
                    $fp,
                    $shard_roots[$global_idx],
                );
                $first_dest_pgno = SharedPgnoCounter::claim($counter_path, $page_count);
                [$pages, $dst_root, $extracted_count] = IndexBtreeExtractor::extractAndRelocate(
                    $fp,
                    $shard_roots[$global_idx],
                    $first_dest_pgno,
                );
                if ($extracted_count !== $page_count) {
                    throw new \RuntimeException(
                        "page count mismatch for slot {$global_idx}: "
                        . "countPages {$page_count} vs extract {$extracted_count}"
                    );
                }
                foreach ($pages as $page_entry) {
                    /** @var array{0: int, 1: string} $page_entry */
                    [$pgno, $bytes] = $page_entry;
                    LibcFileWriter::memcpyFromString(
                        $data_ptr,
                        ($pgno - 1) * self::PAGE_SIZE,
                        $bytes,
                    );
                }
                $meta_offset = $global_idx * self::META_BYTES_PER_SLOT;
                LibcFileWriter::memcpyFromString(
                    $meta_ptr,
                    $meta_offset,
                    pack('N', $dst_root) . pack('N', $page_count) . str_repeat("\x00", 8),
                );
            }
        } finally {
            fclose($fp);
        }
    }

    /**
     * Returns the SELECT expression that yields main's rowid for a
     * given source table — the INTEGER PRIMARY KEY column alias for
     * tables that have one, or `rowid` for those that don't.
     * Mirrors {@see PdoMemoryOutput::createTables}'s schema choices
     * and stays in sync with it: context_nodes uses a compound PK
     * (no rowid alias), the other three tables use `id`.
     */
    private static function sourceRowIdExpression(string $table): string
    {
        return $table === 'context_nodes' ? 'rowid' : 'id';
    }

    private static function pageCount(string $db_path): ?int
    {
        try {
            $db = new \PDO("sqlite:{$db_path}");
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $r = (int)$db->query('PRAGMA page_count')->fetchColumn();
            unset($db);
            return $r;
        } catch (\Throwable) {
            return null;
        }
    }

    private static ?\FFI $reader_ffi = null;

    /** Read META_BYTES_PER_SLOT from $meta_ptr at $offset. */
    private static function readMetaSlot(\FFI\CData $meta_ptr, int $offset): string
    {
        if (self::$reader_ffi === null) {
            self::$reader_ffi = \FFI::cdef(
                'typedef unsigned char uint8_t;'
                . 'void *memcpy(void *dest, const void *src, unsigned long n);',
                null,
            );
        }
        $ffi = self::$reader_ffi;
        $len = self::META_BYTES_PER_SLOT;
        /** @var \FFI\CData $buf */
        $buf = $ffi->new("uint8_t[{$len}]");
        /** @var \FFI\CArray<int> $src_view */
        $src_view = $ffi->cast('uint8_t*', $meta_ptr);
        /** @psalm-suppress InvalidArgument */
        $ffi->memcpy($buf, \FFI::addr($src_view[$offset]), $len);
        return \FFI::string($buf, $len);
    }
}
