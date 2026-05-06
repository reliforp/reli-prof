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
 * The parallel-CREATE-INDEX path's correctness depends on every
 * pgno reference inside an extracted index b-tree being rewritten
 * to the destination's pgno space. End-to-end test: build a real
 * SQLite DB with a multi-page text index, extract it via
 * IndexBtreeExtractor, write the relocated pages into a
 * second SQLite DB at a fresh pgno range, repoint that DB's
 * sqlite_master.rootpage, and verify the index is queryable and
 * returns the same rows.
 */
class IndexBtreeExtractorTest extends BaseTestCase
{
    public function testRelocatesMultiPageTextIndexEndToEnd(): void
    {
        $src_path = tempnam(sys_get_temp_dir(), 'ibe_src_');
        self::assertNotFalse($src_path);
        $src_path .= '.db';
        $dst_path = tempnam(sys_get_temp_dir(), 'ibe_dst_');
        self::assertNotFalse($dst_path);
        $dst_path .= '.db';

        try {
            // Build source: 5000 rows with a TEXT key whose index
            // spans multiple leaves and at least one interior level.
            $src = new \PDO("sqlite:{$src_path}");
            $src->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $src->exec('PRAGMA page_size = 4096');
            $src->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, k TEXT NOT NULL, v INTEGER)');
            $stmt = $src->prepare('INSERT INTO t (id, k, v) VALUES (?, ?, ?)');
            $src->beginTransaction();
            for ($i = 1; $i <= 5000; $i++) {
                $stmt->execute([$i, sprintf('key-%05d-%s', $i, str_repeat('p', 32)), $i * 10]);
            }
            $src->commit();
            $src->exec('CREATE INDEX idx_t_k ON t (k)');
            $idx_rootpage_in_src = (int)$src->query(
                "SELECT rootpage FROM sqlite_master WHERE name = 'idx_t_k'"
            )->fetchColumn();
            unset($stmt, $src);

            // Build destination: same schema + table data, NO user
            // index yet. We'll add the sqlite_master row pointing at
            // the relocated index pages via writable_schema; that
            // matches what the orchestrator does (no empty pre-
            // created index → no orphan pages flagged by
            // integrity_check).
            $dst = new \PDO("sqlite:{$dst_path}");
            $dst->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $dst->exec('PRAGMA page_size = 4096');
            $dst->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, k TEXT NOT NULL, v INTEGER)');
            $stmt = $dst->prepare('INSERT INTO t (id, k, v) VALUES (?, ?, ?)');
            $dst->beginTransaction();
            for ($i = 1; $i <= 5000; $i++) {
                $stmt->execute([$i, sprintf('key-%05d-%s', $i, str_repeat('p', 32)), $i * 10]);
            }
            $dst->commit();
            $dst_dbsize_pages = (int)$dst->query('PRAGMA page_count')->fetchColumn();
            unset($stmt, $dst);

            // Extract the index b-tree from src into the empty tail
            // of dst.
            $src_fp = fopen($src_path, 'rb');
            self::assertNotFalse($src_fp);
            $first_dest_pgno = $dst_dbsize_pages + 1;
            [$pages, $dst_root_pgno, $page_count] = IndexBtreeExtractor::extractAndRelocate(
                $src_fp,
                $idx_rootpage_in_src,
                $first_dest_pgno,
            );
            fclose($src_fp);
            self::assertGreaterThan(1, $page_count, 'multi-page index expected');
            self::assertSame($first_dest_pgno, $dst_root_pgno);

            // Append relocated pages to dst.
            $total_pages = $first_dest_pgno + $page_count - 1;
            $dst_fp = fopen($dst_path, 'r+b');
            self::assertNotFalse($dst_fp);
            ftruncate($dst_fp, $total_pages * 4096);
            foreach ($pages as [$pgno, $bytes]) {
                fseek($dst_fp, ($pgno - 1) * 4096);
                fwrite($dst_fp, $bytes);
            }
            // Update in-header page count.
            fseek($dst_fp, 28);
            fwrite($dst_fp, pack('N', $total_pages));
            fclose($dst_fp);

            // Inject the sqlite_master row pointing at the relocated
            // index root. writable_schema = ON lets us INSERT into
            // sqlite_master directly.
            $dst = new \PDO("sqlite:{$dst_path}");
            $dst->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $dst->exec('PRAGMA writable_schema = ON');
            $stmt = $dst->prepare(
                'INSERT INTO sqlite_master (type, name, tbl_name, rootpage, sql)'
                . ' VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                'index',
                'idx_t_k',
                't',
                $dst_root_pgno,
                'CREATE INDEX idx_t_k ON t (k)',
            ]);
            $dst->exec('PRAGMA writable_schema = OFF');
            unset($stmt, $dst);

            // Re-open and verify the relocated index is healthy.
            $dst = new \PDO("sqlite:{$dst_path}");
            $dst->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            self::assertSame(
                'ok',
                (string)$dst->query('PRAGMA integrity_check')->fetchColumn(),
            );

            // Spot-check: SELECT by key uses the index and returns
            // the right rows.
            $needle = sprintf('key-%05d-%s', 2500, str_repeat('p', 32));
            $stmt = $dst->prepare('SELECT v FROM t WHERE k = ?');
            $stmt->execute([$needle]);
            self::assertSame(25000, (int)$stmt->fetchColumn());

            // Range scan over a chunk to exercise multiple leaves.
            $stmt = $dst->prepare(
                'SELECT COUNT(*) FROM t WHERE k > ? AND k < ?'
            );
            $stmt->execute([
                sprintf('key-%05d', 1000),
                sprintf('key-%05d', 2000),
            ]);
            // 'key-01000-...' through 'key-01999-...' (the longer
            // strings sort after the bare prefix), so 1000 matches.
            self::assertSame(1000, (int)$stmt->fetchColumn());
        } finally {
            @unlink($src_path);
            @unlink($dst_path);
        }
    }
}
