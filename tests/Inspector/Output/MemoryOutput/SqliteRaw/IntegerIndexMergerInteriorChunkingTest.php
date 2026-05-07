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

use Reli\BaseTestCase;

/**
 * Regression test for the duplicate-rightmost interior-cell bug in
 * IntegerIndexMerger that surfaced on PR #773 once any indexed table
 * crossed ~7K rows (validation report:
 * docs/internals/pr-773-sqlite-output-validation.md).
 *
 * For a non-final chunk in the interior-tree assembly the last entry's
 * pgno was used as the chunk's rightmost-child pointer while still
 * remaining a cell in the same chunk, so that pgno appeared on the
 * resulting interior page twice — once as cell index `max_children-1`
 * and once as the rightmost pointer in the page header. PRAGMA
 * integrity_check reported it as
 * "page X cell N: 2nd reference to page Y" plus
 * "wrong # of entries in index ...", and queries that picked the
 * corrupt index as a covering index returned over-counted results.
 *
 * The fixture writes a single sort run with enough sorted (key, rowid)
 * pairs to push the leaf level past `max_children = 123` so the
 * interior-tree loop fires, then walks the resulting index b-tree
 * directly. Pre-fix the walker finds a page reachable through two
 * different parents and the entry total exceeds the input row count;
 * post-fix every page is reached exactly once and the entry total
 * equals the input row count exactly.
 *
 * `integrity_check` is intentionally not the assertion vehicle here:
 * the production caller (PdoMemoryOutput::allocateRun) creates the
 * empty index *before* any rows are inserted into the table, so the
 * merger overwrites a fresh empty rootpage with no orphans. A
 * synthetic test that lets SQLite auto-build the index first leaves
 * "Page X: never used" lines in integrity_check unrelated to this
 * bug; the structural walker checks the property the bug actually
 * violates.
 *
 * @psalm-suppress PossiblyInvalidArrayAccess
 */
class IntegerIndexMergerInteriorChunkingTest extends BaseTestCase
{
    public function testInteriorChunkingProducesValidIndex(): void
    {
        // 25K rows × ~177 cells/leaf for the (1, 8-byte, 8-byte)
        // index payload shape => ~141 leaves, comfortably above
        // max_children = 123 so the interior-tree loop runs at least
        // once. Pre-fix the resulting tree has the duplicate-pgno
        // shape on the first interior page; post-fix the tree is
        // structurally clean.
        $row_count = 25000;
        // ≥ 2^47 forces RecordEncoder into the 8-byte-int branch
        // (type code 6); the 6-byte branch (type code 5) packs ~215
        // cells per leaf and 25K rows would only produce ~116
        // leaves, below the max_children = 123 threshold the bug
        // hides behind.
        $base_key = 1 << 50;

        $base = tempnam(sys_get_temp_dir(), 'iim_');
        self::assertNotFalse($base);
        $main_path = $base . '_main.sqlite3';
        $sort_run_path = $base . '_run';

        try {
            // Mirror the production setup in PdoMemoryOutput::allocateRun:
            // create the empty index before the table has any rows so the
            // merger overwrites a brand-new empty rootpage rather than a
            // SQLite-built tree we'd then orphan.
            $main = new \PDO("sqlite:{$main_path}");
            $main->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $main->exec('PRAGMA page_size = 4096');
            $main->exec('CREATE TABLE t (run_id INTEGER, k INTEGER, v INTEGER)');
            $main->exec('CREATE INDEX idx_t_run_k ON t(run_id, k)');
            $rootpage = (int)$main->query(
                "SELECT rootpage FROM sqlite_master WHERE name = 'idx_t_run_k'"
            )->fetchColumn();
            unset($main);

            $writer = new SortRunWriter($sort_run_path, run_id: 1);
            // Strictly ascending (key, rowid) — the merger contract.
            // 8-byte-wide values keep the encoded payload in the
            // 8-byte-int branch of RecordEncoder, matching real-world
            // dump sizing.
            for ($i = 0; $i < $row_count; $i++) {
                $writer->append($base_key + $i, $base_key + $i);
            }
            $writer->close();

            $main_writer = Writer::open($main_path);
            $merger = new IntegerIndexMerger(
                $main_writer,
                [new SortRunReader($sort_run_path)],
            );
            $merger->merge($rootpage);
            $main_writer->close($main_path);

            [$visited_pages, $entry_count] = $this->walkIndex($main_path, $rootpage);

            // The actual bug: at least one page was reachable from
            // two distinct parents. Pre-fix the walker raises on
            // re-entering an already-visited page; post-fix every
            // page is reached exactly once.
            self::assertSame(
                count($visited_pages),
                count(array_unique($visited_pages)),
                'each page in the index b-tree must be reached exactly once',
            );

            // The user-visible bug: covering-index COUNT(*) over-counts.
            // Total b-tree entries = leaf cells + interior cells (the
            // promoted dividers); should equal the input row count.
            self::assertSame(
                $row_count,
                $entry_count,
                'merged index must carry exactly one entry per input row',
            );
        } finally {
            @unlink($main_path);
            @unlink($sort_run_path);
        }
    }

    /**
     * Bug B regression: when a non-rightmost partition's entry count
     * is exactly `(cells_per_leaf + 1) * k + 1` (i.e. one orphan cell
     * past a clean leaf-cycle boundary), the tail used to fall back
     * to "single-cell leaf with self-divider" — same cell stored in
     * both the leaf and the parent interior page. PRAGMA
     * integrity_check reports it as "wrong # of entries in index"
     * and the table-side full scan double-counts the cell as a
     * silent extra row with a duplicated rowid.
     *
     * The fixture builds one partition's worth of leaves directly
     * via `extractLeavesForRange` with `is_global_rightmost = false`
     * and an entry count chosen to land exactly on the bug's
     * (272k+1)-shape. For `(1-int run_id, int32 key, int32 rowid)`
     * cells the per-leaf packing is 271 cells + 1 promoted divider
     * = 272 entries per leaf cycle, so an entry count of
     * `272 + 1 = 273` lands on the smallest k=1 trigger.
     *
     * The post-fix expectation: total b-tree entries (leaf cells +
     * promoted dividers across the returned `leaves` list) equals
     * the input row count exactly. Pre-fix the count was off by
     * exactly +1 because the lone tail cell appeared in both a
     * 1-cell leaf and as the divider above it.
     */
    public function testNonRightmostPartitionTailDoesNotDuplicateLastCell(): void
    {
        // 273 entries = 1 trigger of the (272k + 1) shape. With
        // small int32-shaped keys (run_id=1, key, rowid) the cell
        // is 13 bytes and the per-leaf cycle is 272 entries, so 273
        // = 1 full leaf cycle (271 in leaf + 1 promoted) + 1 tail
        // cell. The tail-merge path under test re-absorbs that lone
        // cell back into the previous leaf and uses it as the new
        // divider.
        $row_count = 273;

        $base = tempnam(sys_get_temp_dir(), 'iim_partB_');
        self::assertNotFalse($base);
        $main_path = $base . '_main.sqlite3';
        $sort_run_path = $base . '_run';

        try {
            $main = new \PDO("sqlite:{$main_path}");
            $main->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $main->exec('PRAGMA page_size = 4096');
            $main->exec('CREATE TABLE t (run_id INTEGER, k INTEGER, v INTEGER)');
            unset($main);

            $writer = new SortRunWriter($sort_run_path, run_id: 1);
            for ($i = 1; $i <= $row_count; $i++) {
                $writer->append($i, $i);
            }
            $writer->close();

            $main_writer = Writer::open($main_path);
            $merger = new IntegerIndexMerger(
                $main_writer,
                [new SortRunReader($sort_run_path)],
            );
            // is_global_rightmost = false simulates a non-rightmost
            // partition; this is the path that triggered the bug.
            $result = $merger->extractLeavesForRange(null, null, false);
            $main_writer->close($main_path);

            $entry_count = 0;
            foreach ($result['leaves'] as $entry) {
                $entry_count += $this->countLeafCells(
                    $main_path,
                    $entry['pgno'],
                );
                $entry_count += 1; // promoted divider for this leaf
            }

            self::assertNull(
                $result['rightmost_pgno'],
                'non-rightmost partition must not return an explicit rightmost',
            );
            self::assertSame(
                $row_count,
                $entry_count,
                'non-rightmost tail must not duplicate the lone cell as both leaf and divider',
            );
        } finally {
            @unlink($main_path);
            @unlink($sort_run_path);
        }
    }

    /**
     * Count the number of cells in an index-leaf page at `$pgno`.
     */
    private function countLeafCells(string $path, int $pgno): int
    {
        $reader = Reader::open($path);
        $page = $reader->readPage($pgno);
        $btree_offset = $pgno === 1 ? Format::HEADER_SIZE : 0;
        $type = ord($page[$btree_offset]);
        if ($type !== Format::BTREE_LEAF_INDEX) {
            throw new \RuntimeException(
                sprintf(
                    'expected index leaf page at pgno %d, got type 0x%02x',
                    $pgno,
                    $type,
                ),
            );
        }
        return (int)unpack('n', substr($page, $btree_offset + Format::PG_CELL_COUNT, 2))[1];
    }

    /**
     * Walk the index b-tree rooted at `$rootpage` and return the list
     * of every page visited (in DFS order) and the total number of
     * b-tree entries (leaf cells + interior-page divider cells).
     *
     * Throws \RuntimeException at the first page that gets walked into
     * twice — that is the structural shape of the duplicate-pgno bug.
     *
     * @return array{list<int>, int}
     */
    private function walkIndex(string $path, int $rootpage): array
    {
        $reader = Reader::open($path);
        $visited = [];
        $entries = 0;
        $this->walkIndexPage($reader, $rootpage, $visited, $entries);
        return [$visited, $entries];
    }

    /**
     * @param list<int> $visited
     */
    private function walkIndexPage(Reader $reader, int $pgno, array &$visited, int &$entries): void
    {
        if (in_array($pgno, $visited, true)) {
            throw new \RuntimeException(
                "page {$pgno} reached twice during index walk — duplicate-pgno corruption",
            );
        }
        $visited[] = $pgno;

        $page = $reader->readPage($pgno);
        $btree_offset = $pgno === 1 ? Format::HEADER_SIZE : 0;
        $type = ord($page[$btree_offset]);
        $cell_count = (int)unpack('n', substr($page, $btree_offset + Format::PG_CELL_COUNT, 2))[1];
        $entries += $cell_count;

        if ($type === Format::BTREE_LEAF_INDEX) {
            return;
        }
        if ($type !== Format::BTREE_INTERIOR_INDEX) {
            throw new \RuntimeException(
                sprintf('expected index b-tree page at %d, got type 0x%02x', $pgno, $type),
            );
        }

        $rightmost = (int)unpack('N', substr($page, $btree_offset + Format::PG_RIGHTMOST_PTR, 4))[1];
        $cell_ptr_array_offset = $btree_offset + 12; // 8-byte hdr + 4-byte rightmost
        for ($i = 0; $i < $cell_count; $i++) {
            $cell_ptr = (int)unpack(
                'n',
                substr($page, $cell_ptr_array_offset + $i * 2, 2),
            )[1];
            // Interior index cell: u32 left_child_pgno + varint payload_size + payload
            $child_pgno = (int)unpack('N', substr($page, $cell_ptr, 4))[1];
            $this->walkIndexPage($reader, $child_pgno, $visited, $entries);
        }
        $this->walkIndexPage($reader, $rightmost, $visited, $entries);
    }
}
