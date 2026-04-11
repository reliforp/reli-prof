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
        return $this->libc->pread($this->fd, FFI::addr($buf[0]), $size, $offset);
    }

    /**
     * Try to bind the three libc calls we need. libc's location is
     * platform-dependent, so try the usual candidates in order. PHP
     * FFI with a null library name looks up symbols from RTLD_DEFAULT
     * which on glibc systems already has libc loaded into the main
     * binary — that's the first attempt since it avoids a dlopen.
     */
    private static function bindLibc(): ?FFI
    {
        $cdef = 'int open(const char *pathname, int flags);'
            . 'int close(int fd);'
            . 'long pread(int fd, void *buf, unsigned long count, long offset);';
        $candidates = [null, 'libc.so.6', 'libc.so', 'libSystem.dylib'];
        foreach ($candidates as $lib) {
            try {
                return FFI::cdef($cdef, $lib);
            } catch (\Throwable) {
                // try next candidate
            }
        }
        return null;
    }
}
