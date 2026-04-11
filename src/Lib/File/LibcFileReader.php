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
 * Thin libc wrapper that lets the memory dump reader pread(2) directly
 * into an FFI CData buffer instead of going through
 * fopen/fseek/fread/memcpy. Saves one syscall per read (lseek + read →
 * pread) and eliminates the PHP-string intermediate that fread produces.
 *
 * Usage:
 *
 *   $reader = LibcFileReader::open('/path/to/dump');
 *   if ($reader !== null) {
 *       $n = $reader->pread($cdata_buf, $size, $file_offset);
 *   }
 *
 * Returns null when the libc binding can't be loaded (e.g. on a
 * non-Linux platform or a PHP build without FFI); callers must fall
 * back to the fopen/fread path in that case.
 */
final class LibcFileReader
{
    private int $fd = -1;

    /**
     * @param \FFI\Libc\libc_file_ffi $libc typed via the bundled stub
     *     in `tools/stubs/ffi/libc.php` so static analysis sees the
     *     declared method signatures (open / close / pread /
     *     posix_fadvise) instead of the opaque \FFI base class.
     */
    private function __construct(
        private FFI $libc,
        private string $path,
    ) {
    }

    /**
     * Open `$path` read-only through libc pread. Returns null on any
     * failure — missing FFI extension, missing libc, non-POSIX
     * platform, open(2) failure — so the caller can cleanly fall
     * back to the PHP stream path.
     */
    public static function open(string $path): ?self
    {
        if (!extension_loaded('ffi')) {
            return null;
        }
        $libc = self::bindLibc();
        if ($libc === null) {
            return null;
        }
        $instance = new self($libc, $path);
        // O_RDONLY = 0 on every POSIX system that matters.
        $fd = $libc->open($path, 0);
        if ($fd < 0) {
            return null;
        }
        $instance->fd = $fd;
        return $instance;
    }

    public function __destruct()
    {
        if ($this->fd >= 0) {
            $this->libc->close($this->fd);
            $this->fd = -1;
        }
    }

    /**
     * Read exactly `$size` bytes at `$offset` directly into `$buf`.
     * Returns the number of bytes actually read (may be less than
     * `$size` on EOF) or a negative value on error.
     */
    public function pread(CData $buf, int $size, int $offset): int
    {
        assert($this->fd >= 0);
        // PHP FFI marshals CData as a pointer to its underlying
        // storage when passed to a function expecting `void*`, so
        // there's no need to FFI::addr the first element manually.
        return $this->libc->pread($this->fd, $buf, $size, $offset);
    }

    /**
     * Hint the kernel that the entire file at `$path` will be needed
     * soon, via posix_fadvise(POSIX_FADV_WILLNEED). On Linux this kicks
     * off an asynchronous read-ahead that pulls the file into the
     * kernel page cache while the caller continues other work — by
     * the time SQLite (or anything else) starts pread()ing the file,
     * most pages are already in cache and the per-page syscall cost
     * collapses to a memcpy from cache.
     *
     * Self-contained fd lifecycle: opens, fadvises, closes. Returns
     * true on success, false if FFI / libc / posix_fadvise are
     * unavailable on this platform (e.g. PHP without FFI, macOS where
     * posix_fadvise is not exported under that name) or if the file
     * couldn't be opened.
     */
    public static function prefetchFile(string $path): bool
    {
        if (!extension_loaded('ffi')) {
            return false;
        }
        $libc = self::bindLibc();
        if ($libc === null) {
            return false;
        }
        $fd = $libc->open($path, 0);
        if ($fd < 0) {
            return false;
        }
        try {
            // POSIX_FADV_WILLNEED = 3 on Linux. (offset=0, len=0) is
            // the documented "whole file from offset 0 to end" form.
            $rc = $libc->posix_fadvise($fd, 0, 0, 3);
            return $rc === 0;
        } catch (\Throwable) {
            // posix_fadvise symbol missing — non-Linux platform, or
            // a libc that doesn't export it under this name.
            return false;
        } finally {
            $libc->close($fd);
        }
    }

    /**
     * Try to bind the libc calls we need. libc's location is
     * platform-dependent, so try the usual candidates in order. PHP
     * FFI with a null library name looks up symbols from RTLD_DEFAULT
     * which on glibc systems already has libc loaded into the main
     * binary — that's the first attempt since it avoids a dlopen.
     *
     * posix_fadvise is declared in the cdef but resolved lazily on
     * first call, so platforms missing the symbol still load the
     * binding cleanly and only fail when prefetchFile() is invoked.
     *
     * @return \FFI\Libc\libc_file_ffi|null typed via the bundled
     *     stub so static analysis sees the declared method
     *     signatures (open / close / pread / posix_fadvise) on the
     *     returned binding instead of the opaque \FFI base class.
     */
    private static function bindLibc(): ?FFI
    {
        $cdef = 'int open(const char *pathname, int flags);'
            . 'int close(int fd);'
            . 'long pread(int fd, void *buf, unsigned long count, long offset);'
            . 'int posix_fadvise(int fd, long offset, long len, int advice);';
        $candidates = [null, 'libc.so.6', 'libc.so', 'libSystem.dylib'];
        foreach ($candidates as $lib) {
            try {
                /** @var \FFI\Libc\libc_file_ffi */
                return FFI::cdef($cdef, $lib);
            } catch (\Throwable) {
                // try next candidate
            }
        }
        return null;
    }
}
