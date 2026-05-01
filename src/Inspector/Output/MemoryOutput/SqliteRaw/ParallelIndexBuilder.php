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
     * Per-(worker, index) page reservation. Sparse file so the
     * upper bound only matters for offset arithmetic — unused
     * tails are recovered. 16384 pgnos = 64 MiB is comfortable
     * for any single index built from a reasonable-sized table.
     */
    public const int PAGES_PER_INDEX_RESERVATION = 16384;

    /** Bytes the worker meta region uses per (worker, index) slot. */
    private const int META_BYTES_PER_SLOT = 16;

    /**
     * Build the supplied indexes in parallel and return the list of
     * unused pgnos for the caller to put on the freelist, plus the
     * final effective page count of the file. Returns null when
     * fork or FFI is unavailable or any worker fails.
     *
     * `$index_specs` is a list of `[index_name, table_name, sql]`
     * tuples. The orchestrator runs `$sql` in each shard, looks up
     * the index's rootpage by `index_name` afterwards, and inserts
     * the `(name, table_name, sql, rootpage)` tuple into the main's
     * sqlite_master.
     *
     * @param list<array{string, string, string}> $index_specs
     * @return array{list<int>, int}|null [unused_pgnos, effective_total_pages]
     */
    public static function build(
        string $main_db_path,
        array $index_specs,
        int $worker_count,
    ): ?array {
        if (
            !extension_loaded('ffi')
            || !function_exists('pcntl_fork')
            || !function_exists('pcntl_waitpid')
        ) {
            return null;
        }
        if ($index_specs === []) {
            return [[], 0];
        }
        $worker_count = max(1, min($worker_count, count($index_specs)));

        // Bucket indexes round-robin across workers.
        $buckets = array_fill(0, $worker_count, []);
        foreach ($index_specs as $i => $spec) {
            $buckets[$i % $worker_count][] = [$i, $spec];
        }

        // Determine current main DB size and ftruncate to the
        // generous upper bound the workers can safely write into.
        $current_pages = self::pageCount($main_db_path);
        if ($current_pages === null) {
            return null;
        }
        $reservation_per_idx = self::PAGES_PER_INDEX_RESERVATION;
        $first_pgno = $current_pages + 1;
        // Per (worker, idx) reservations laid out contiguously in
        // worker-then-index order so a worker's reservations are
        // contiguous and its bucket index trivially picks them out.
        $worker_reservations = [];
        $next_pgno = $first_pgno;
        foreach ($buckets as $w => $items) {
            foreach ($items as $j => [$global_idx, $_spec]) {
                $worker_reservations[$global_idx] = $next_pgno;
                $next_pgno += $reservation_per_idx;
            }
        }
        $total_pages = $next_pgno - 1;

        // Pre-grow main DB (sparse).
        $libc = \FFI::cdef('int open(const char *, int, int);', null);
        /** @var int $fd */
        $fd = $libc->open($main_db_path, 2, 0);
        if ($fd < 0) {
            return null;
        }
        if (!LibcFileWriter::ftruncate($fd, $total_pages * self::PAGE_SIZE)) {
            LibcFileWriter::close($fd);
            return null;
        }
        LibcFileWriter::close($fd);

        // Meta region: per (worker, idx) slot of META_BYTES_PER_SLOT
        // bytes carrying [root_pgno (4 BE)][page_count (4 BE)]
        // [reserved (8)]. One mmap'd file shared across the fork.
        $meta_path = $main_db_path . '.pib_meta';
        $meta_size = count($index_specs) * self::META_BYTES_PER_SLOT;
        file_put_contents($meta_path, str_repeat("\x00", $meta_size));

        $data_res = LibcFileWriter::mmapShared($main_db_path, $total_pages * self::PAGE_SIZE);
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
                            $worker_reservations,
                            $reservation_per_idx,
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

            // Read meta region back. For each index: actual root pgno
            // and actual page count. Compute unused pgnos.
            $unused_pgnos = [];
            $index_results = [];
            $highest_used_pgno = $current_pages;
            foreach ($index_specs as $i => $spec) {
                $offset = $i * self::META_BYTES_PER_SLOT;
                $bytes = self::readMetaSlot($meta_ptr, $offset);
                /** @var array{root: int, count: int} $u */
                $u = unpack('Nroot/Ncount', $bytes);
                $root = (int)$u['root'];
                $page_count = (int)$u['count'];
                if ($root === 0 || $page_count === 0) {
                    return null;
                }
                $reservation_start = $worker_reservations[$i];
                $reservation_end = $reservation_start + $reservation_per_idx - 1;
                $actual_end = $reservation_start + $page_count - 1;
                for ($p = $actual_end + 1; $p <= $reservation_end; $p++) {
                    $unused_pgnos[] = $p;
                }
                if ($actual_end > $highest_used_pgno) {
                    $highest_used_pgno = $actual_end;
                }
                $index_results[$spec[0]] = [
                    'table' => $spec[1],
                    'sql' => $spec[2],
                    'root' => $root,
                ];
            }

            // Trim unused pgnos that abut the file's end so we can
            // ftruncate them away rather than freelisting.
            $effective_total_pages = $total_pages;
            if ($unused_pgnos !== []) {
                sort($unused_pgnos);
                $last_used = $effective_total_pages;
                while (
                    $unused_pgnos !== []
                    && $unused_pgnos[count($unused_pgnos) - 1] === $last_used
                ) {
                    array_pop($unused_pgnos);
                    $last_used--;
                }
                $effective_total_pages = $last_used;
            }

            LibcFileWriter::msync($data_ptr, $data_len);
        } finally {
            LibcFileWriter::munmap($data_ptr, $data_len);
            LibcFileWriter::munmap($meta_ptr, $meta_len);
            LibcFileWriter::close($meta_fd);
            // Trim trailing unused pages from the data file before
            // closing — must happen after munmap.
            if (
                isset($effective_total_pages)
                && $effective_total_pages < $total_pages
            ) {
                LibcFileWriter::ftruncate($data_fd, $effective_total_pages * self::PAGE_SIZE);
            }
            LibcFileWriter::close($data_fd);
            @unlink($meta_path);
        }

        if ($any_failure) {
            return null;
        }

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

        return [$unused_pgnos, $effective_total_pages];
    }

    /**
     * @param list<array{int, array{string, string, string}}> $bucket
     * @param array<int, int> $worker_reservations
     */
    private static function runWorker(
        string $main_db_path,
        string $shard_path,
        array $bucket,
        array $worker_reservations,
        int $reservation_per_idx,
        \FFI\CData $data_ptr,
        \FFI\CData $meta_ptr,
    ): void {
        if (!copy($main_db_path, $shard_path)) {
            throw new \RuntimeException("failed to cp main → {$shard_path}");
        }
        $db = new \PDO("sqlite:{$shard_path}");
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA synchronous = OFF');
        $db->exec('PRAGMA journal_mode = MEMORY');
        $db->exec('PRAGMA temp_store = MEMORY');
        $db->exec('PRAGMA cache_size = -262144');
        $db->exec('PRAGMA threads = 2');

        // Phase 1: native CREATE INDEX for each assigned spec; record
        // each new index's rootpage in the shard.
        $shard_roots = [];
        foreach ($bucket as [$global_idx, $spec]) {
            [$index_name, $_table, $sql] = $spec;
            $db->exec($sql);
            $shard_roots[$global_idx] = (int)$db->query(
                'SELECT rootpage FROM sqlite_master WHERE name = '
                . $db->quote($index_name)
            )->fetchColumn();
            if ($shard_roots[$global_idx] <= 0) {
                throw new \RuntimeException("CREATE INDEX produced no rootpage for {$index_name}");
            }
        }
        // Close PDO so the shard file is consistent before we read
        // it back as raw bytes.
        unset($db);

        // Phase 2: open shard as bytes, extract each index's b-tree
        // pages, memcpy them into main's mmap at the reserved range.
        $fp = fopen($shard_path, 'rb');
        if ($fp === false) {
            throw new \RuntimeException("failed to open shard {$shard_path}");
        }
        try {
            foreach ($bucket as [$global_idx, $_spec]) {
                $first_dest_pgno = $worker_reservations[$global_idx];
                [$pages, $dst_root, $page_count] = IndexBtreeExtractor::extractAndRelocate(
                    $fp,
                    $shard_roots[$global_idx],
                    $first_dest_pgno,
                );
                if ($page_count > $reservation_per_idx) {
                    throw new \RuntimeException(
                        "index slot {$global_idx} needs {$page_count} pages, "
                        . "reservation only {$reservation_per_idx}"
                    );
                }
                foreach ($pages as [$pgno, $bytes]) {
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
