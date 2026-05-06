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
 * The shared-mmap parallel writer's correctness rests on the leaf
 * pages it emits being byte-identical to what SQLite would have
 * produced for the same INSERTs. We pin that with a fresh-DB
 * cross-check: insert N rows via PDO, read the rootpage bytes back,
 * and compare against {@see LeafPageBuilder::assembleLeafPage} fed
 * the same cells. Any divergence in header layout, pointer-array
 * ordering, or freelist allocation strategy fails the assertion.
 */
class LeafPageBuilderTest extends BaseTestCase
{
    public function testRejectsEmptyPage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LeafPageBuilder::assembleLeafPage([], 0);
    }

    public function testMatchesSqliteOnDiskPageBytes(): void
    {
        $base = tempnam(sys_get_temp_dir(), 'lpb_');
        self::assertNotFalse($base);
        $tmp = $base . '.db';
        try {
            $db = new \PDO("sqlite:{$tmp}");
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $db->exec('PRAGMA page_size = 4096');
            $db->exec('CREATE TABLE t (a INTEGER, b TEXT)');
            $stmt = $db->prepare('INSERT INTO t (rowid, a, b) VALUES (?, ?, ?)');
            $rows = [
                [1, 100, 'first'],
                [2, 200, 'second'],
                [3, 300, 'third'],
                [4, null, ''],
                [5, -1, str_repeat('z', 32)],
            ];
            foreach ($rows as $row) {
                $stmt->execute($row);
            }
            unset($stmt, $db);

            $bytes = (string)file_get_contents($tmp);
            $sqlite_page = substr($bytes, 4096, 4096);

            $cells = [];
            $cells_bytes = 0;
            foreach ($rows as [$rowid, $a, $b]) {
                $cell = TableCellEncoder::encodeTableLeafCell($rowid, [$a, $b]);
                $cells[] = $cell;
                $cells_bytes += strlen($cell);
            }
            $ours = LeafPageBuilder::assembleLeafPage($cells, $cells_bytes);

            self::assertSame(
                bin2hex($sqlite_page),
                bin2hex($ours),
                'assembled leaf page diverges from SQLite on-disk bytes',
            );
        } finally {
            @unlink($tmp);
        }
    }

    public function testStreamsCellsAcrossPagesAndReturnsIndex(): void
    {
        $emitted = [];
        $builder = new LeafPageBuilder(
            first_pgno: 5,
            max_pages: 1024,
            emit: function (int $pgno, string $page) use (&$emitted): void {
                $emitted[] = [$pgno, $page];
            },
        );
        // Each cell ~ 25 bytes payload + small header → ~28 bytes.
        // 4096 - 8 - 2*N ≈ 4080 → ~140 cells per page. Push enough to
        // span 3 pages.
        $cells_in_pages = [];
        $current_page_cells = [];
        for ($i = 1; $i <= 400; $i++) {
            $payload = "row-{$i}-" . str_repeat('x', 16);
            $builder->append($i, TableCellEncoder::encodeTableLeafCell($i, [$i, $payload]));
        }
        $index = $builder->finalize();

        self::assertGreaterThanOrEqual(2, count($emitted));
        self::assertSame(count($emitted), count($index));
        // pgno sequence is contiguous starting at first_pgno.
        foreach ($emitted as $i => [$pgno, $page]) {
            self::assertSame(5 + $i, $pgno);
            self::assertSame(LeafPageBuilder::PAGE_SIZE, strlen($page));
            self::assertSame("\x0d", $page[0], 'page must be table-leaf type');
        }
        // page_index records strictly-ascending max_rowids.
        $prev = 0;
        foreach ($index as [$max_rowid, $pgno]) {
            self::assertGreaterThan($prev, $max_rowid);
            $prev = $max_rowid;
        }
        $last = $index[array_key_last($index)];
        self::assertSame(400, $last[0]);
    }

    public function testEnforcesPageBudget(): void
    {
        $builder = new LeafPageBuilder(
            first_pgno: 2,
            max_pages: 1,
            emit: function (): void {
            },
        );
        $this->expectException(\RuntimeException::class);
        for ($i = 1; $i <= 1000; $i++) {
            $builder->append($i, TableCellEncoder::encodeTableLeafCell($i, [$i, str_repeat('x', 64)]));
        }
    }
}
