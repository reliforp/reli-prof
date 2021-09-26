<?php
namespace FFI\Libc;
use FFI\CData;
use FFI\CInteger;

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

class iovec extends CData
{
    /** @var int|CInteger  */
    public int $iov_base;
    /** @var int|CInteger  */
    public int $iov_len;
}

class process_vm_readv_ffi extends \FFI
{
    /** @var int|CInteger */
    public int $errno;

    /**
     * @param int $pid
     * @param CData $local_iov_addr
     * @param int $liovcnt
     * @param CData $remote_iov_addr
     * @param int $riovcnt
     * @param int $flags
     * @return int|CInteger
     */
    public function process_vm_readv(int $pid, CData $local_iov_addr, int $liovcnt, CData $remote_iov_addr, int $riovcnt, int $flags): int {}
}

class errno_ffi extends \FFI
{
    /** @var int|CInteger */
    public int $errno;
}

class execvp_ffi extends \FFI
{
    public function execvp(string $file, CData $argv): int {}
}

class ptrace_ffi extends \FFI
{
    /**
     * @param int $request
     * @param int $pid
     * @param CData $addr
     * @param CData $data
     * @return int
     */
    public function ptrace(int $request, int $pid, CData $addr, CData $data): int {}
}

class libc_file_ffi extends \FFI
{
    public function open(string $path, int $mode): int {}
    public function read(int $fd, CData $buffer, int $size): int {}
    public function close(int $fd) {}
}