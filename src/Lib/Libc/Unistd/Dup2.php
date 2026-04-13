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

namespace Reli\Lib\Libc\Unistd;

final class Dup2
{
    /** @var \FFI\Libc\dup2_ffi */
    private \FFI $ffi;

    public function __construct()
    {
        /** @var \FFI\Libc\dup2_ffi */
        $this->ffi = \FFI::cdef('
            int open(const char *pathname, int flags, int mode);
            int dup2(int oldfd, int newfd);
            int close(int fd);
       ', 'libc.so.6');
    }

    public const STDOUT_FILENO = 1;
    public const STDERR_FILENO = 2;
    public const O_WRONLY = 1;
    public const O_CREAT = 64;
    public const O_TRUNC = 512;

    public function redirectToFile(string $path, int $target_fd): void
    {
        $fd = $this->ffi->open($path, self::O_WRONLY | self::O_CREAT | self::O_TRUNC, 0644);
        if ($fd < 0) {
            return;
        }
        $this->ffi->dup2($fd, $target_fd);
        $this->ffi->close($fd);
    }
}
