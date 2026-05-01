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

namespace Reli\Lib\File;

use Reli\BaseTestCase;

/**
 * @psalm-suppress PossiblyFalseOperand
 * @psalm-suppress MixedAssignment
 */
class LibcFileWriterTest extends BaseTestCase
{
    private string $path;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $base = tempnam(sys_get_temp_dir(), 'libcfw_');
        self::assertNotFalse($base);
        $this->path = $base . '.bin';
        file_put_contents($this->path, '');
        // Pre-grow to 16 KiB so every test below has room for four
        // 4 KiB pages.
        $libc = \FFI::cdef(
            'int open(const char *pathname, int flags, int mode);'
            . 'int ftruncate(int fd, long length);'
            . 'int close(int fd);',
            null,
        );
        $fd = $libc->open($this->path, 2, 0);
        self::assertGreaterThanOrEqual(0, $fd);
        self::assertSame(0, $libc->ftruncate($fd, 16384));
        $libc->close($fd);
    }

    #[\Override]
    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function testMemcpyFromStringWritesAtOffset(): void
    {
        $res = LibcFileWriter::mmapShared($this->path, 16384);
        self::assertNotNull($res);
        [$ptr, $len, $fd] = $res;
        self::assertSame(16384, $len);

        LibcFileWriter::memcpyFromString($ptr, 0, 'HELLO');
        LibcFileWriter::memcpyFromString($ptr, 4096, 'OFFSET-4096');
        LibcFileWriter::msync($ptr, $len);
        LibcFileWriter::munmap($ptr, $len);
        LibcFileWriter::close($fd);

        $bytes = (string)file_get_contents($this->path);
        self::assertSame('HELLO', substr($bytes, 0, 5));
        self::assertSame('OFFSET-4096', substr($bytes, 4096, 11));
    }

    /**
     * The whole point of the writer is forked workers writing
     * disjoint regions of the same shared mapping without IPC.
     * This test pins that behaviour: parent maps + ftruncate'd
     * the file, four children inherit the mapping, each writes a
     * sentinel at its assigned 4 KiB slot, parent msyncs and reads
     * back. If the mapping wasn't truly shared (e.g. inherited as
     * MAP_PRIVATE COW on fork), at most one slot would survive.
     */
    public function testForkedWorkersShareTheMapping(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl extension is required for shared-mmap fork test');
        }
        $res = LibcFileWriter::mmapShared($this->path, 16384);
        self::assertNotNull($res);
        [$ptr, $len, $fd] = $res;

        $pids = [];
        for ($i = 0; $i < 4; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                LibcFileWriter::memcpyFromString($ptr, $i * 4096, "WORKER-$i");
                exit(0);
            }
            self::assertGreaterThan(0, $pid);
            $pids[] = $pid;
        }
        $status = 0;
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status));
        }
        LibcFileWriter::msync($ptr, $len);
        LibcFileWriter::munmap($ptr, $len);
        LibcFileWriter::close($fd);

        $bytes = (string)file_get_contents($this->path);
        for ($i = 0; $i < 4; $i++) {
            self::assertSame(
                "WORKER-$i",
                substr($bytes, $i * 4096, 8),
                "slot $i should hold worker $i's sentinel",
            );
        }
    }

    public function testFtruncateGrowsAndShrinks(): void
    {
        $libc = \FFI::cdef('int open(const char *pathname, int flags, int mode); int close(int fd);', null);
        $fd = $libc->open($this->path, 2, 0);
        self::assertGreaterThanOrEqual(0, $fd);
        try {
            self::assertTrue(LibcFileWriter::ftruncate($fd, 32768));
            clearstatcache();
            self::assertSame(32768, filesize($this->path));
            self::assertTrue(LibcFileWriter::ftruncate($fd, 8192));
            clearstatcache();
            self::assertSame(8192, filesize($this->path));
        } finally {
            $libc->close($fd);
        }
    }
}
