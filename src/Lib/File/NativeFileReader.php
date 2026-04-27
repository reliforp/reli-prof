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

use FFI;
use Reli\Lib\FFI\CannotAllocateBufferException;

final class NativeFileReader implements FileReaderInterface
{
    // libphp.so is in the order of 20 MB on FrankenPHP-style builds, so a
    // 4 KB chunk loop fed through ".=" turned readAll into the dominant
    // cold-attach hot spot (~98% of total time). 1 MB chunks bring the
    // syscall count down by 256x and let the kernel's readahead actually
    // do its job.
    private const CHUNK_SIZE = 1024 * 1024;

    /** @var FFI\Libc\libc_file_ffi */
    private FFI $ffi;

    public function __construct()
    {
        // PHP's stream wrappers run paths through realpath(), which follows
        // /proc/<pid>/root symlinks back to "/" and breaks container-path
        // resolution -- which is the whole reason this reader exists.
        // Open() / read() / close() over FFI sidesteps that. lseek lets us
        // size the file with one syscall before allocating the destination
        // buffer, which is portable across glibc versions and architectures
        // (struct stat layout differs between x86_64 and aarch64, so calling
        // __fxstat() and reading the bytes by hand corrupts memory on ARM).
        // pread is used for the actual transfer so no seek state has to be
        // maintained.
        //
        // Types are spelled with explicit `long` / `unsigned long` /
        // `int` rather than `size_t` / `ssize_t` / `off_t`. PHP FFI's
        // cdef parser only recognises the C primitive types from the
        // language spec; size_t and friends silently fall through to a
        // shorter type, which produces an ABI mismatch on aarch64 (the
        // upper bits of the size argument arrive as garbage and pread
        // ends up writing past the buffer, surfacing as
        // "zend_mm_heap corrupted" in the test runner). The other libc
        // bindings in this codebase (LibcFileReader, MemoryDumper) use
        // the same explicit-types convention.
        /** @var FFI\Libc\libc_file_ffi */
        $this->ffi = FFI::cdef(
            'int open(const char *pathname, int flags);'
            . 'long pread(int fd, void *buf, unsigned long count, long offset);'
            . 'long read(int fd, void *buf, unsigned long count);'
            . 'long lseek(int fd, long offset, int whence);'
            . 'int close(int fd);',
        );
    }

    #[\Override]
    public function readSlice(string $path, int $offset, int $size): string
    {
        if ($size <= 0) {
            return '';
        }
        $fd = $this->ffi->open($path, 0);
        if ($fd < 0) {
            return '';
        }
        try {
            $buffer = $this->ffi->new("unsigned char[{$size}]")
                ?? throw new CannotAllocateBufferException('cannot allocate buffer');
            $read_len = $this->ffi->pread($fd, $buffer, $size, $offset);
            if ($read_len <= 0) {
                return '';
            }
            return FFI::string($buffer, $read_len);
        } finally {
            $this->ffi->close($fd);
        }
    }

    #[\Override]
    public function readAll(string $path): string
    {
        $fd = $this->ffi->open($path, 0);
        if ($fd < 0) {
            return '';
        }

        try {
            $size = $this->seekToSize($fd);
            if ($size > 0) {
                return $this->readKnownSize($fd, $size);
            }
            return $this->readUntilEof($fd);
        } finally {
            $this->ffi->close($fd);
        }
    }

    private function seekToSize(int $fd): int
    {
        // SEEK_END = 2. Returns the new offset, i.e. the file size, or -1
        // on error. /proc files and pipes report 0 (or an error) and fall
        // through to readUntilEof. Regular files on disk give us the real
        // size in one syscall, no struct-stat ABI guessing required.
        /** @var int $size */
        $size = $this->ffi->lseek($fd, 0, 2);
        if ($size <= 0) {
            return 0;
        }
        return $size;
    }

    private function readKnownSize(int $fd, int $size): string
    {
        // Always read in CHUNK_SIZE-bound segments. Allocating a single
        // multi-MB FFI buffer the size of the whole binary turned out to
        // corrupt zend_mm under load on aarch64 (memory dump tests
        // surfaced "zend_mm_heap corrupted"); 1 MB chunks keep the per-
        // allocation footprint small and reuse one buffer across the
        // loop. The size hint from lseek still helps because it lets us
        // pre-size the implode and bound the loop.
        $chunk_buffer = $this->ffi->new('unsigned char[' . self::CHUNK_SIZE . ']')
            ?? throw new CannotAllocateBufferException('cannot allocate buffer');
        $chunks = [];
        $offset = 0;
        while ($offset < $size) {
            $remaining = $size - $offset;
            $more = $this->ffi->pread(
                $fd,
                $chunk_buffer,
                $remaining < self::CHUNK_SIZE ? $remaining : self::CHUNK_SIZE,
                $offset,
            );
            if ($more <= 0) {
                break;
            }
            $chunks[] = FFI::string($chunk_buffer, $more);
            $offset += $more;
        }
        return implode('', $chunks);
    }

    private function readUntilEof(int $fd): string
    {
        $buffer = $this->ffi->new('unsigned char[' . self::CHUNK_SIZE . ']')
            ?? throw new CannotAllocateBufferException('cannot allocate buffer');
        $chunks = [];
        while (true) {
            $read_len = $this->ffi->read($fd, $buffer, self::CHUNK_SIZE);
            if ($read_len <= 0) {
                break;
            }
            $chunks[] = FFI::string($buffer, $read_len);
        }
        return implode('', $chunks);
    }
}
