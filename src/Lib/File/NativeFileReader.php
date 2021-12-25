<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Lib\File;

use FFI;
use PhpProfiler\Lib\FFI\CannotAllocateBufferException;

class NativeFileReader implements FileReaderInterface
{
    /** @var FFI\Libc\libc_file_ffi  */
    private FFI $ffi;

    public function __construct()
    {
        /** @var FFI\Libc\libc_file_ffi */
        $this->ffi = FFI::cdef('
            int open(const char *pathname, int flags);
            ssize_t read(int fd, void *buf, size_t count);
            int close(int fd);
        ');
    }

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
                $result .= FFI::string($buffer, min($read_len, 4096));
            } else {
                $done = true;
            }
        } while (!$done);
        $this->ffi->close($fd);

        return $result;
    }
}
