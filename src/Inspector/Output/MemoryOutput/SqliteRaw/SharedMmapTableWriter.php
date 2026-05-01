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

use FFI\CData;
use Reli\Lib\File\LibcFileWriter;

/**
 * Orchestrates a shared-mmap parallel write of one or more SQLite
 * tables.
 *
 * Workers (forked by the caller) call {@see workerWriteTable} once
 * per table they own, encoding cells with {@see TableCellEncoder} and
 * streaming 4 KiB leaf pages into a pre-mapped region of the main DB
 * file via {@see LibcFileWriter::memcpyFromString}. After the workers
 * exit, the main process calls {@see mainAssembleInteriorTree} for
 * each table to build the interior b-tree from the leaves and place
 * the root at the table's existing `sqlite_master.rootpage`.
 *
 * The plan/layout is computed up-front by {@see plan}; the caller is
 * responsible for `ftruncate`-ing the file to the planned size and
 * `mmap`-ing it before forking. `$first_mapped_pgno` tells the writer
 * the pgno of the first page covered by the mmap (typically 1, since
 * the existing schema rootpages live in the leading pages of the
 * file and the writer needs to reach them too when overwriting the
 * `sqlite_master.rootpage` of each table).
 *
 * Overflow pages are not yet supported: cells exceeding the table-leaf
 * inline payload limit (~`page_size - 35` bytes) cause the worker to
 * throw {@see CellTooLargeException}, which the orchestrator catches
 * to fall back to the existing parallel-shard path.
 */
final class SharedMmapTableWriter
{
    public const int PAGE_SIZE = 4096;
    public const int INLINE_PAYLOAD_LIMIT = self::PAGE_SIZE - 35;
    public const int META_BYTES_PER_WORKER_TABLE = 8;

    /**
     * Allocate per-table page ranges across workers, plus an interior
     * page reservation per table.
     *
     * `$tables` is an associative map `name => [row_count, est_leaf_pages_per_worker]`.
     * `$first_pgno` is the lowest pgno the writer may use (typically
     * just after the schema rootpages already in the file).
     *
     * @param array<string, array{int, int}> $tables
     */
    public static function plan(
        array $tables,
        int $first_pgno,
        int $worker_count,
        int $interior_reserve_per_table = 64,
    ): SharedMmapPlan {
        $next_pgno = $first_pgno;
        $worker_leaf_ranges = [];
        $interior_ranges = [];
        foreach ($tables as $table_name => [$row_count, $leaf_pages_per_worker]) {
            $worker_leaf_ranges[$table_name] = [];
            for ($i = 0; $i < $worker_count; $i++) {
                $worker_leaf_ranges[$table_name][$i] = [$next_pgno, $leaf_pages_per_worker];
                $next_pgno += $leaf_pages_per_worker;
            }
            $interior_ranges[$table_name] = [$next_pgno, $interior_reserve_per_table];
            $next_pgno += $interior_reserve_per_table;
        }
        return new SharedMmapPlan(
            worker_leaf_ranges: $worker_leaf_ranges,
            interior_ranges: $interior_ranges,
            total_pages_used: $next_pgno - $first_pgno,
            first_pgno: $first_pgno,
        );
    }

    /**
     * Worker-side: encode the supplied row stream as table-leaf cells
     * and stream 4 KiB pages into the shared mmap at the worker's
     * assigned page range. Writes the actual page count back to the
     * meta region so the main process knows where the worker's
     * leaves ended.
     *
     * `$rows` yields `[rowid, list<int|string|null>]` tuples in
     * ascending rowid order. Rowids must lie in the worker's slice
     * (no overlap with other workers).
     *
     * @param \Generator<int, array{int, list<int|string|null>}> $rows
     */
    public static function workerWriteTable(
        CData $data_ptr,
        CData $meta_ptr,
        SharedMmapPlan $plan,
        string $table_name,
        int $worker_index,
        \Generator $rows,
        int $first_mapped_pgno = 1,
    ): void {
        [$first_leaf_pgno, $max_pages] = $plan->workerLeafRange($table_name, $worker_index);

        $builder = new LeafPageBuilder(
            first_pgno: $first_leaf_pgno,
            max_pages: $max_pages,
            emit: static function (
                int $pgno,
                string $page
            ) use (
                $data_ptr,
                $first_mapped_pgno,
            ): void {
                LibcFileWriter::memcpyFromString(
                    $data_ptr,
                    ($pgno - $first_mapped_pgno) * self::PAGE_SIZE,
                    $page,
                );
            },
        );

        foreach ($rows as [$rowid, $values]) {
            $cell = TableCellEncoder::encodeTableLeafCell($rowid, $values);
            if (strlen($cell) > self::INLINE_PAYLOAD_LIMIT) {
                throw new CellTooLargeException(
                    "table {$table_name} cell at rowid {$rowid} is "
                    . strlen($cell) . " bytes, exceeds inline limit " . self::INLINE_PAYLOAD_LIMIT,
                );
            }
            $builder->append($rowid, $cell);
        }
        $builder->finalize();

        $meta_offset = $plan->metaOffsetFor($table_name, $worker_index);
        LibcFileWriter::memcpyFromString(
            $meta_ptr,
            $meta_offset,
            pack('P', $builder->pagesWritten()),
        );
    }

    /**
     * Main-side: read each worker's pages_written count from the
     * meta region, parse the trailing cell of each leaf to extract
     * its max rowid, build the multi-level interior tree, and emit
     * interior pages with the root at `$existing_rootpage`.
     *
     * Returns the list of pgnos that ended up unused (worker
     * over-reservation tail + interior range tail). The caller is
     * responsible for marking those as freelist pages via
     * {@see SqliteFileMaintainer::finalize}.
     *
     * @return list<int> unused pgnos
     */
    public static function mainAssembleInteriorTree(
        CData $data_ptr,
        CData $meta_ptr,
        SharedMmapPlan $plan,
        string $table_name,
        int $worker_count,
        int $existing_rootpage,
        int $first_mapped_pgno = 1,
    ): array {
        $unused = [];
        $leaf_index = [];

        for ($w = 0; $w < $worker_count; $w++) {
            $meta_offset = $plan->metaOffsetFor($table_name, $w);
            $bytes = self::readBytes($meta_ptr, $meta_offset, 8);
            /** @var array{1: int} $unpacked */
            $unpacked = unpack('P', $bytes);
            $pages_written = $unpacked[1];

            [$worker_first_pgno, $reserved] = $plan->workerLeafRange($table_name, $w);
            for ($p = 0; $p < $pages_written; $p++) {
                $pgno = $worker_first_pgno + $p;
                $page_bytes = self::readBytes(
                    $data_ptr,
                    ($pgno - $first_mapped_pgno) * self::PAGE_SIZE,
                    self::PAGE_SIZE,
                );
                $max_rowid = self::extractMaxRowidFromLeaf($page_bytes);
                $leaf_index[] = [$max_rowid, $pgno];
            }
            for ($p = $pages_written; $p < $reserved; $p++) {
                $unused[] = $worker_first_pgno + $p;
            }
        }

        [$interior_first, $interior_count] = $plan->interiorRange($table_name);
        if (count($leaf_index) === 0) {
            // No data → leave the original empty leaf rootpage as-is,
            // mark the entire interior reservation as unused.
            for ($p = 0; $p < $interior_count; $p++) {
                $unused[] = $interior_first + $p;
            }
            return $unused;
        }

        if (count($leaf_index) === 1) {
            // Single-leaf table — copy that leaf's bytes onto the
            // existing sqlite_master rootpage, mark the leaf and
            // the entire interior reservation as unused.
            [$_max, $only_pgno] = $leaf_index[0];
            $page_bytes = self::readBytes(
                $data_ptr,
                ($only_pgno - $first_mapped_pgno) * self::PAGE_SIZE,
                self::PAGE_SIZE,
            );
            LibcFileWriter::memcpyFromString(
                $data_ptr,
                ($existing_rootpage - $first_mapped_pgno) * self::PAGE_SIZE,
                $page_bytes,
            );
            $unused[] = $only_pgno;
            for ($p = 0; $p < $interior_count; $p++) {
                $unused[] = $interior_first + $p;
            }
            return $unused;
        }

        [$interior_pages, $root_pgno] = TableInteriorPageBuilder::build(
            $leaf_index,
            $interior_first,
            root_pgno_hint: $existing_rootpage,
        );
        if ($root_pgno !== $existing_rootpage) {
            throw new \RuntimeException('interior builder did not honour root_pgno_hint');
        }

        $interior_pgnos_used = [];
        foreach ($interior_pages as [$pgno, $page]) {
            LibcFileWriter::memcpyFromString(
                $data_ptr,
                ($pgno - $first_mapped_pgno) * self::PAGE_SIZE,
                $page,
            );
            if ($pgno !== $existing_rootpage) {
                $interior_pgnos_used[$pgno] = true;
            }
        }
        for ($p = 0; $p < $interior_count; $p++) {
            $pgno = $interior_first + $p;
            if (!isset($interior_pgnos_used[$pgno])) {
                $unused[] = $pgno;
            }
        }

        return $unused;
    }

    /** PHP 8.4-safe FFI binding for byte reads from the mmap region. */
    private static ?\FFI $reader_ffi = null;

    /**
     * Read `$length` bytes from a CData pointer at `$offset` into a
     * fresh PHP string. Goes through `cast() + addr() + memcpy()` on
     * the bound FFI instance — same pattern LibcFileWriter uses for
     * its writes, so we don't get bitten by the static-method
     * deprecation in PHP 8.4 nor by sub-view GC ordering issues.
     *
     * @psalm-suppress MixedAssignment
     * @psalm-suppress MixedArgument
     */
    private static function readBytes(CData $ptr, int $offset, int $length): string
    {
        if (self::$reader_ffi === null) {
            self::$reader_ffi = \FFI::cdef(
                'typedef unsigned char uint8_t;'
                . 'void *memcpy(void *dest, const void *src, unsigned long n);',
                null,
            );
        }
        $ffi = self::$reader_ffi;
        /** @var \FFI\CData $buf */
        $buf = $ffi->new("uint8_t[{$length}]");
        /** @var \FFI\CArray<int> $src_view */
        $src_view = $ffi->cast('uint8_t*', $ptr);
        /** @psalm-suppress InvalidArgument */
        $ffi->memcpy($buf, \FFI::addr($src_view[$offset]), $length);
        return \FFI::string($buf, $length);
    }

    /**
     * Find the max rowid in a table-leaf page by parsing the cell
     * pointed to by the last entry in the cell pointer array (cells
     * are stored in rowid-ascending order in the pointer array).
     */
    private static function extractMaxRowidFromLeaf(string $page): int
    {
        /** @var array{1: int} $cc */
        $cc = unpack('n', substr($page, 3, 2));
        $cell_count = $cc[1];
        if ($cell_count === 0) {
            throw new \RuntimeException('leaf page has zero cells');
        }
        /** @var array{1: int} $co */
        $co = unpack('n', substr($page, 8 + ($cell_count - 1) * 2, 2));
        $cell_offset = $co[1];
        [$_payload_size, $sz_len] = Format::readVarint($page, $cell_offset);
        [$rowid, $_rowid_len] = Format::readVarint($page, $cell_offset + $sz_len);
        return $rowid;
    }
}
