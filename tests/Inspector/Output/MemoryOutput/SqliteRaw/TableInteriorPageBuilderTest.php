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
 * The interior page builder produces b-tree internal nodes that
 * SQLite has to traverse to find leaves. A miscounted cell, a
 * swapped right_most_pgno, or a forgotten divider means SELECTs miss
 * rows or return garbage. The end-to-end test below builds a full
 * SQLite DB from raw pages — leaves via LeafPageBuilder, interior
 * via TableInteriorPageBuilder, sqlite_master rootpage rewritten via
 * writable_schema — and runs SQLite's own `integrity_check` plus a
 * scan to confirm every rowid is reachable.
 */
class TableInteriorPageBuilderTest extends BaseTestCase
{
    public function testSingleLeafReturnsItDirectly(): void
    {
        [$pages, $root] = TableInteriorPageBuilder::build([[42, 7]], 100);
        self::assertSame([], $pages);
        self::assertSame(7, $root);
    }

    public function testSingleLevelInteriorMatchesExpectedShape(): void
    {
        $leaf_index = [];
        for ($i = 1; $i <= 5; $i++) {
            $leaf_index[] = [$i * 100, 10 + $i]; // (max_rowid, leaf_pgno)
        }
        [$pages, $root] = TableInteriorPageBuilder::build($leaf_index, 50);
        self::assertCount(1, $pages);
        self::assertSame(50, $root);

        [$pgno, $bytes] = $pages[0];
        self::assertSame(50, $pgno);
        self::assertSame("\x05", $bytes[0]);
        $cell_count = (int)(unpack('n', substr($bytes, 3, 2))[1] ?? 0);
        self::assertSame(4, $cell_count, '5 children → 4 divider cells, rightmost in header');
        $right_most = (int)(unpack('N', substr($bytes, 8, 4))[1] ?? 0);
        self::assertSame(15, $right_most, 'rightmost child pgno = last leaf pgno');

        // Walk pointer array, parse cells, confirm divider rowids and child pgnos.
        $expected = [[100, 11], [200, 12], [300, 13], [400, 14]];
        for ($i = 0; $i < $cell_count; $i++) {
            $cell_offset = (int)(unpack('n', substr($bytes, 12 + $i * 2, 2))[1] ?? 0);
            $child_pgno = (int)(unpack('N', substr($bytes, $cell_offset, 4))[1] ?? 0);
            [$rowid, $_] = Format::readVarint($bytes, $cell_offset + 4);
            self::assertSame($expected[$i][0], $rowid, "cell {$i} divider rowid");
            self::assertSame($expected[$i][1], $child_pgno, "cell {$i} child pgno");
        }
    }

    public function testMultiLevelTreeFromManyLeaves(): void
    {
        // 1000 leaves → can't fit in a single interior page (cap is
        // MAX_INTERIOR_CHILDREN = 512), so the builder must produce
        // a 3-level tree: leaves → ~2 interior level-1 pages →
        // 1 level-2 root.
        $leaf_index = [];
        for ($i = 1; $i <= 1000; $i++) {
            $leaf_index[] = [$i * 10, 100 + $i];
        }
        [$pages, $root] = TableInteriorPageBuilder::build($leaf_index, 5000);
        self::assertGreaterThanOrEqual(3, count($pages));
        // Root must come last in build order (top of the tree).
        $last = $pages[count($pages) - 1];
        self::assertSame($root, $last[0]);
        // Root page bytes must encode the cells we expect for a
        // pure level-2 page (children = level-1 pages).
        $root_bytes = $last[1];
        self::assertSame("\x05", $root_bytes[0]);
    }

    public function testEndToEndBuildsValidSqliteDatabase(): void
    {
        $base = tempnam(sys_get_temp_dir(), 'tib_');
        self::assertNotFalse($base);
        $path = $base . '.db';
        try {
            // Bootstrap: create the schema via PDO, then close so we
            // can rewrite raw pages without lock conflicts.
            $db = new \PDO("sqlite:{$path}");
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $db->exec('PRAGMA page_size = 4096');
            $db->exec('CREATE TABLE t (a INTEGER, b TEXT)');
            $current_rootpage = (int)$db->query(
                "SELECT rootpage FROM sqlite_master WHERE name = 't'"
            )->fetchColumn();
            self::assertGreaterThanOrEqual(2, $current_rootpage);
            unset($db);

            // Build leaf pages from cells, allocating leaf pgnos
            // starting just past the file's current end. Row count
            // and payload size are tuned to force a ≥2-level interior
            // tree so the multi-level promotion code path actually
            // runs end-to-end through SQLite.
            $row_count = 50000;
            $rows = [];
            for ($i = 1; $i <= $row_count; $i++) {
                $rows[] = [$i, "value-{$i}-" . str_repeat('p', 24)];
            }
            $first_leaf_pgno = (int)(filesize($path) / 4096) + 1;
            $leaf_pages = [];
            $builder = new LeafPageBuilder(
                first_pgno: $first_leaf_pgno,
                max_pages: 100000,
                emit: function (int $pgno, string $page) use (&$leaf_pages): void {
                    $leaf_pages[$pgno] = $page;
                },
            );
            foreach ($rows as $i => [$a, $b]) {
                $rowid = $i + 1;
                $cell = TableCellEncoder::encodeTableLeafCell($rowid, [$a, $b]);
                $builder->append($rowid, $cell);
            }
            $leaf_index = $builder->finalize();
            self::assertGreaterThan(1, count($leaf_index), 'need multi-leaf tree');

            // Build interior tree above the leaves, telling the
            // builder to place the final root at the original
            // sqlite_master.rootpage location so no UPDATE is needed
            // and the original empty page isn't orphaned.
            $first_interior_pgno = $first_leaf_pgno + count($leaf_pages);
            [$interior_pages, $root_pgno] = TableInteriorPageBuilder::build(
                $leaf_index,
                $first_interior_pgno,
                root_pgno_hint: $current_rootpage,
            );
            self::assertNotEmpty($interior_pages);
            self::assertSame($current_rootpage, $root_pgno);
            self::assertGreaterThanOrEqual(
                3,
                count($interior_pages),
                '50K rows must produce a ≥2-level interior tree (multiple level-1 pages + root)',
            );

            $non_root_count = 0;
            foreach ($interior_pages as [$pgno, $_]) {
                if ($pgno !== $root_pgno) {
                    $non_root_count++;
                }
            }
            $total_pages = $first_interior_pgno + $non_root_count - 1;

            $fp = fopen($path, 'r+b');
            self::assertNotFalse($fp);
            ftruncate($fp, $total_pages * 4096);
            foreach ($leaf_pages as $pgno => $page) {
                fseek($fp, ($pgno - 1) * 4096);
                fwrite($fp, $page);
            }
            foreach ($interior_pages as [$pgno, $page]) {
                fseek($fp, ($pgno - 1) * 4096);
                fwrite($fp, $page);
            }
            // Update in-header database size (offset 28, big-endian u32).
            fseek($fp, 28);
            fwrite($fp, pack('N', $total_pages));
            fclose($fp);

            // Re-open and verify SQLite can walk our tree.
            $db = new \PDO("sqlite:{$path}");
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $integrity = (string)$db->query('PRAGMA integrity_check')->fetchColumn();
            self::assertSame('ok', $integrity);
            $count = (int)$db->query('SELECT COUNT(*) FROM t')->fetchColumn();
            self::assertSame($row_count, $count);
            // Spot-check a row near the start, middle, and end.
            foreach ([1, $row_count / 2, $row_count] as $rowid) {
                $stmt = $db->prepare('SELECT a, b FROM t WHERE rowid = ?');
                $stmt->execute([(int)$rowid]);
                $row = $stmt->fetch(\PDO::FETCH_NUM);
                self::assertNotFalse($row, "rowid {$rowid} must be reachable");
                self::assertSame((int)$rowid, (int)$row[0]);
            }
            // Full scan must hit all rowids in order.
            $stmt = $db->query('SELECT rowid FROM t ORDER BY rowid');
            $i = 1;
            foreach ($stmt as $row) {
                self::assertSame($i, (int)$row['rowid']);
                $i++;
            }
            self::assertSame($row_count + 1, $i);
        } finally {
            @unlink($path);
        }
    }
}
