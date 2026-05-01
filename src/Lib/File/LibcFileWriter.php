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
use FFI\CData;

/**
 * Libc-backed writer for read-write mmap.
 *
 * Used by the parallel format-direct merger: one shared mapping
 * allows forked workers to write to disjoint page ranges of the
 * same file without IPC, then a single msync flushes the result.
 *
 * Mirrors `LibcFileReader`'s lazy FFI binding pattern; falls back
 * to returning null when libc, FFI, or mmap isn't available so
 * callers can transparently degrade to a non-format-direct path.
 *
 * @psalm-suppress UnsupportedReferenceUsage
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedArgument
 * @psalm-suppress MixedMethodCall
 * @psalm-suppress MixedReturnStatement
 * @psalm-suppress MixedReturnTypeCoercion
 */
final class LibcFileWriter
{
    // Linux x86_64/aarch64 fcntl.h constants. mmap.h flags also.
    private const int O_RDWR  = 2;
    private const int PROT_READ = 1;
    private const int PROT_WRITE = 2;
    private const int MAP_SHARED = 1;
    private const int MS_SYNC = 4;

    /** @var FFI|null|false null=not tried, false=failed */
    private static FFI|null|false $libc = null;

    /**
     * Open `$path` in O_RDWR mode and mmap it shared. The caller is
     * responsible for ensuring the file already exists and has been
     * sized via `ftruncate` to at least `$length` bytes — typically
     * the caller pre-grows it after creating the SQLite schema.
     *
     * @return array{CData, int, int}|null [ptr, length, fd] or null on
     *     failure (FFI / libc / mmap unavailable, or open/mmap errno).
     */
    public static function mmapShared(string $path, int $length): ?array
    {
        if (!extension_loaded('ffi')) {
            return null;
        }
        $libc = self::bindLibc();
        if ($libc === null) {
            return null;
        }
        if ($length <= 0) {
            return null;
        }
        $fd = $libc->open($path, self::O_RDWR, 0);
        if ($fd < 0) {
            return null;
        }
        try {
            $ptr = $libc->mmap(
                null,
                $length,
                self::PROT_READ | self::PROT_WRITE,
                self::MAP_SHARED,
                $fd,
                0,
            );
            if ($ptr === null || FFI::isNull($ptr)) {
                $libc->close($fd);
                return null;
            }
            return [$ptr, $length, $fd];
        } catch (\Throwable) {
            $libc->close($fd);
            return null;
        }
    }

    /**
     * Grow / shrink an open file. Returns true on success.
     */
    public static function ftruncate(int $fd, int $length): bool
    {
        $libc = self::bindLibc();
        if ($libc === null) {
            return false;
        }
        return $libc->ftruncate($fd, $length) === 0;
    }

    /**
     * Synchronously flush dirty pages of the mapping back to disk.
     */
    public static function msync(CData $ptr, int $length): bool
    {
        $libc = self::bindLibc();
        if ($libc === null) {
            return false;
        }
        return $libc->msync($ptr, $length, self::MS_SYNC) === 0;
    }

    public static function munmap(CData $ptr, int $length): void
    {
        $libc = self::bindLibc();
        if ($libc === null) {
            return;
        }
        $libc->munmap($ptr, $length);
    }

    public static function close(int $fd): void
    {
        $libc = self::bindLibc();
        if ($libc === null) {
            return;
        }
        $libc->close($fd);
    }

    /**
     * Bulk-copy a PHP string into the mapping at the given byte
     * offset. Convenience for callers that have an assembled page
     * (or any byte blob) on the PHP side and want to drop it into
     * the shared region without touching every byte from PHP.
     */
    public static function memcpyFromString(CData $ptr, int $offset, string $bytes): void
    {
        $libc = self::bindLibc();
        if ($libc === null) {
            throw new \RuntimeException('libc binding required');
        }
        /** @var \FFI\CArray<int> $bytes_view */
        $bytes_view = $libc->cast('uint8_t*', $ptr);
        // Psalm sees $bytes_view[$offset] as int (the dereferenced
        // byte value), but PHP FFI lets us take a pointer to the
        // underlying CData at runtime — same trick the existing
        // DerivedCacheReader uses for its int[] buffers.
        /** @psalm-suppress InvalidArgument */
        FFI::memcpy(FFI::addr($bytes_view[$offset]), $bytes, strlen($bytes));
    }

    private static function bindLibc(): ?FFI
    {
        if (self::$libc === false) {
            return null;
        }
        if (self::$libc !== null) {
            return self::$libc;
        }
        $cdef = 'int open(const char *pathname, int flags, int mode);'
            . 'int close(int fd);'
            . 'int ftruncate(int fd, long length);'
            . 'void *mmap(void *addr, unsigned long length, int prot, int flags, int fd, long offset);'
            . 'int munmap(void *addr, unsigned long length);'
            . 'int msync(void *addr, unsigned long length, int flags);';
        $candidates = [null, 'libc.so.6', 'libc.so', 'libSystem.dylib'];
        foreach ($candidates as $lib) {
            try {
                self::$libc = FFI::cdef($cdef, $lib);
                return self::$libc;
            } catch (\Throwable) {
                // try next candidate
            }
        }
        self::$libc = false;
        return null;
    }
}
