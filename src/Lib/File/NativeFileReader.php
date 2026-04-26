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
    /** @var FFI\Libc\libc_file_ffi  */
    private FFI $ffi;

    public function __construct()
    {
        /** @var FFI\Libc\libc_file_ffi */
        $this->ffi = FFI::cdef('
            int open(const char *pathname, int flags);
            long read(int fd, void *buf, unsigned long count);
            long pread(int fd, void *buf, unsigned long count, long offset);
            int close(int fd);
        ');
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
        $buffer = $this->ffi->new("unsigned char[4096]")
            ?? throw new CannotAllocateBufferException('cannot allocate buffer');

        $fd = $this->ffi->open($path, 0);
        $result = "";
        $done = false;
        do {
            $read_len = $this->ffi->read($fd, $buffer, 4096);
            if ($read_len > 0) {
                $result .= \FFI::string($buffer, min($read_len, 4096));
            } else {
                $done = true;
            }
        } while (!$done);
        $this->ffi->close($fd);

        return $result;
    }
}
