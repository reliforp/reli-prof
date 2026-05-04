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
 * Regression test for the 0-cell interior page bug that surfaced on
 * a real 50K-object dump (PR #773 review):
 *
 *   if `count(level) % max_children == 1` at any level of the
 *   interior-page chunking, the trailing chunk has a single child,
 *   and `buildInteriorPage([single])` produced a type-0x05 page with
 *   `cell_count = 0` + a rightmost pointer. SQLite spec requires
 *   interior pages to carry at least one cell, so the next walk of
 *   the b-tree (e.g. CREATE INDEX) returned "database disk image is
 *   malformed".
 *
 * Fixed by adding a `take--` lookahead so neither the current nor
 * the next chunk ever ends up with size 1. The fixture below
 * deterministically constructs shards whose total leaf count crosses
 * the boundary, so a regression of either the lookahead OR the
 * underlying buildInteriorPage assumption is caught here rather
 * than only via real-world dumps.
 */
class TableMergerInteriorChunkingTest extends BaseTestCase
{
    /**
     * 273 leaves across the merged shards = 272 (max_children for
     * 4 KiB pages) + 1, the smallest input that triggers a 1-leaf
     * trailing chunk before the lookahead. Pre-fix this drove
     * `buildInteriorPage` into the 0-cell branch and SQLite's
     * `integrity_check` reported the resulting file as malformed
     * the first time anything walked the merged b-tree.
     */
    public function testMergedBtreeIsValidWhenTrailingChunkWouldBeOne(): void
    {
        $base = tempnam(sys_get_temp_dir(), 'tmm_');
        self::assertNotFalse($base);
        $main_path = $base . '_main.sqlite3';
        $shard_paths = [
            $base . '_shard_0.sqlite3',
            $base . '_shard_1.sqlite3',
        ];

        try {
            // ~262 cells per leaf for this 3-int payload schema → 273
            // leaves needs ~72K rows total. Split across 2 shards.
            // The exact threshold matters: we want the merged level
            // to have count = max_children + 1 = 273, so the buggy
            // code path emits a chunk of size 1 (= invalid 0-cell
            // interior page). Calibrated empirically against the
            // current SQLite + page-size combination.
            $rows_per_shard = 36000;
            $total_rows = $rows_per_shard * count($shard_paths);

            // Build shards with disjoint rowid ranges (TableMerger
            // requires globally disjoint contiguous rowids).
            foreach ($shard_paths as $i => $sp) {
                $start_rowid = $i * $rows_per_shard + 1;
                $this->buildShard($sp, $start_rowid, $rows_per_shard);
            }

            // Build main with the same schema, empty.
            $main = new \PDO("sqlite:{$main_path}");
            $main->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $main->exec('PRAGMA page_size = 4096');
            $main->exec('CREATE TABLE t (run_id INTEGER, a INTEGER, b INTEGER)');
            $main_rootpage = (int)$main->query(
                "SELECT rootpage FROM sqlite_master WHERE name = 't'"
            )->fetchColumn();
            unset($main);

            // Run TableMerger directly against the shards.
            $writer = Writer::open($main_path);
            $shards = array_map(
                static fn (string $p): Reader => Reader::open($p),
                $shard_paths,
            );
            $merger = new TableMerger($writer, $shards);
            $merger->merge('t', $main_rootpage);
            $writer->close($main_path);

            // Re-open and verify SQLite reads the merged b-tree
            // correctly. Pre-fix this was where the bug surfaced —
            // either integrity_check would flag the orphan 0-cell
            // interior page, or COUNT(*) would short-walk the tree
            // and return the wrong row count.
            $main = new \PDO("sqlite:{$main_path}");
            $main->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            self::assertSame(
                'ok',
                (string)$main->query('PRAGMA integrity_check')->fetchColumn(),
            );
            self::assertSame(
                $total_rows,
                (int)$main->query('SELECT COUNT(*) FROM t')->fetchColumn(),
            );

            // Spot-check rowids near the boundary between shards
            // (where the 272 / 273 / 1 chunking math would land).
            $stmt = $main->prepare('SELECT a FROM t WHERE rowid = ?');
            foreach ([1, $rows_per_shard, $rows_per_shard + 1, $total_rows] as $rowid) {
                $stmt->execute([$rowid]);
                $a = $stmt->fetchColumn();
                self::assertNotFalse($a, "rowid {$rowid} must be reachable");
                self::assertSame($rowid * 2, (int)$a);
            }
        } finally {
            @unlink($main_path);
            foreach ($shard_paths as $sp) {
                @unlink($sp);
            }
        }
    }

    private function buildShard(string $path, int $start_rowid, int $count): void
    {
        $db = new \PDO("sqlite:{$path}");
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA page_size = 4096');
        $db->exec('PRAGMA synchronous = OFF');
        $db->exec('PRAGMA journal_mode = MEMORY');
        // Schema matches main's: small fixed-size int payload so the
        // resulting leaves carry many cells per page (~150) which is
        // what brings the 273-leaf threshold into reach at ~40K rows.
        $db->exec('CREATE TABLE t (run_id INTEGER, a INTEGER, b INTEGER)');
        $stmt = $db->prepare(
            'INSERT INTO t (rowid, run_id, a, b) VALUES (?, 1, ?, ?)'
        );
        $db->beginTransaction();
        for ($i = 0; $i < $count; $i++) {
            $rowid = $start_rowid + $i;
            $stmt->execute([$rowid, $rowid * 2, $rowid * 3]);
        }
        $db->commit();
        unset($stmt, $db);
    }
}
