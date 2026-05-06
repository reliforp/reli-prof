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
use Reli\Lib\File\LibcFileWriter;

/**
 * End-to-end validation of the shared-mmap parallel write
 * architecture: forked workers each fill an assigned page range of
 * the same `mmap`'d DB file, the parent assembles the interior
 * b-tree from their leaves, the file is then handed to a fresh PDO
 * connection. SQLite's own integrity_check + a full rowid scan
 * confirm the output is a valid database — anything off in the
 * cell encoding, page layout, fork inheritance, msync/munmap order,
 * freelist bookkeeping, or sqlite_master rootpage handling fails
 * the assertion below.
 */
class SharedMmapTableWriterTest extends BaseTestCase
{
    private string $db_path;
    private string $meta_path;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('ffi')) {
            self::markTestSkipped('FFI extension required');
        }
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl extension required');
        }
        $base = tempnam(sys_get_temp_dir(), 'smtw_');
        self::assertNotFalse($base);
        $this->db_path = $base . '.db';
        $this->meta_path = $base . '.meta';
    }

    #[\Override]
    protected function tearDown(): void
    {
        @unlink($this->db_path);
        @unlink($this->meta_path);
        parent::tearDown();
    }

    public function testForkedWorkersWriteDisjointTableLeavesAndMainAssemblesValidDb(): void
    {
        $worker_count = 4;
        $rows_per_worker = 12500;
        $total_rows = $worker_count * $rows_per_worker;

        // Bootstrap schema.
        $db = new \PDO("sqlite:{$this->db_path}");
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA page_size = 4096');
        $db->exec('CREATE TABLE t (a INTEGER, b TEXT)');
        $rootpage = (int)$db->query("SELECT rootpage FROM sqlite_master WHERE name = 't'")
            ->fetchColumn();
        $current_pages = (int)$db->query('PRAGMA page_count')->fetchColumn();
        unset($db);

        // Plan layout. Estimate: each row encodes to ~50 bytes of
        // cell, ~75 cells per page → ~167 leaves per worker; reserve
        // 200 to leave headroom (over-reservation tail goes to freelist).
        $leaf_pages_per_worker = 200;
        $plan = SharedMmapTableWriter::plan(
            tables: ['t' => [$total_rows, $leaf_pages_per_worker]],
            first_pgno: $current_pages + 1,
            worker_count: $worker_count,
            interior_reserve_per_table: 16,
        );
        $total_pages = $current_pages + $plan->totalPagesUsed();

        // Pre-grow data file.
        $data_fd = self::openRdwr($this->db_path);
        self::assertTrue(LibcFileWriter::ftruncate($data_fd, $total_pages * 4096));
        LibcFileWriter::close($data_fd);

        // Pre-grow meta file.
        file_put_contents($this->meta_path, str_repeat("\x00", $plan->metaTotalBytes()));
        $meta_res = LibcFileWriter::mmapShared($this->meta_path, $plan->metaTotalBytes());
        self::assertNotNull($meta_res);
        [$meta_ptr, $meta_len, $meta_fd] = $meta_res;

        // mmap data file. We map the whole file (page 1 through end)
        // so first_mapped_pgno = 1 and offsets are simply (pgno-1)*page_size.
        $data_res = LibcFileWriter::mmapShared($this->db_path, $total_pages * 4096);
        self::assertNotNull($data_res);
        [$data_ptr, $data_len, $data_fd] = $data_res;

        // Fork.
        $pids = [];
        for ($w = 0; $w < $worker_count; $w++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    SharedMmapTableWriter::workerWriteTable(
                        data_ptr: $data_ptr,
                        meta_ptr: $meta_ptr,
                        plan: $plan,
                        table_name: 't',
                        worker_index: $w,
                        rows: self::workerRows($w, $rows_per_worker),
                    );
                    exit(0);
                } catch (\Throwable $e) {
                    fwrite(STDERR, "worker {$w} failed: " . $e->getMessage() . "\n");
                    exit(1);
                }
            }
            self::assertGreaterThan(0, $pid);
            $pids[] = $pid;
        }
        $statuses = [];
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $statuses[] = $status;
        }
        foreach ($statuses as $i => $status) {
            self::assertSame(0, pcntl_wexitstatus($status), "worker {$i} exited cleanly");
        }

        // Main: build interior tree.
        $unused = SharedMmapTableWriter::mainAssembleInteriorTree(
            data_ptr: $data_ptr,
            meta_ptr: $meta_ptr,
            plan: $plan,
            table_name: 't',
            worker_count: $worker_count,
            existing_rootpage: $rootpage,
        );

        // Install unused pages on the freelist + update file header.
        SqliteFileMaintainer::finalize(
            data_ptr: $data_ptr,
            first_mapped_pgno: 1,
            total_pages: $total_pages,
            unused_pgnos: $unused,
        );

        LibcFileWriter::msync($data_ptr, $data_len);
        LibcFileWriter::munmap($data_ptr, $data_len);
        LibcFileWriter::close($data_fd);
        LibcFileWriter::munmap($meta_ptr, $meta_len);
        LibcFileWriter::close($meta_fd);

        // Reopen and verify.
        $db = new \PDO("sqlite:{$this->db_path}");
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $integrity = (string)$db->query('PRAGMA integrity_check')->fetchColumn();
        self::assertSame('ok', $integrity, 'integrity_check must pass');

        $count = (int)$db->query('SELECT COUNT(*) FROM t')->fetchColumn();
        self::assertSame($total_rows, $count, 'all worker rows must be visible');

        // Sample a row from each worker's slice.
        for ($w = 0; $w < $worker_count; $w++) {
            $rowid = $w * $rows_per_worker + 1;
            $stmt = $db->prepare('SELECT a, b FROM t WHERE rowid = ?');
            $stmt->execute([$rowid]);
            $row = $stmt->fetch(\PDO::FETCH_NUM);
            self::assertNotFalse($row, "row {$rowid} from worker {$w}");
            self::assertSame($rowid, (int)$row[0]);
            self::assertSame("worker-{$w}-row-{$rowid}", (string)$row[1]);
        }

        // Full ordered scan.
        $i = 1;
        foreach ($db->query('SELECT rowid FROM t ORDER BY rowid') as $row) {
            self::assertSame($i, (int)$row['rowid']);
            $i++;
        }
        self::assertSame($total_rows + 1, $i);
    }

    /**
     * @return \Generator<int, array{int, list<int|string|null>}>
     */
    private static function workerRows(int $worker_index, int $rows_per_worker): \Generator
    {
        $first_rowid = $worker_index * $rows_per_worker + 1;
        for ($i = 0; $i < $rows_per_worker; $i++) {
            $rowid = $first_rowid + $i;
            yield [$rowid, [$rowid, "worker-{$worker_index}-row-{$rowid}"]];
        }
    }

    private static function openRdwr(string $path): int
    {
        $libc = \FFI::cdef('int open(const char *pathname, int flags, int mode);', null);
        $fd = $libc->open($path, 2, 0);
        self::assertGreaterThanOrEqual(0, $fd);
        return $fd;
    }
}
