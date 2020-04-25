<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpProfiler\Lib\Process\MemoryReader;

use FFI;
use FFI\CData;

/**
 * Class MemoryReader
 * @package PhpProfiler\Lib\Process
 */
final class MemoryReader implements MemoryReaderInterface
{
    private FFI $ffi;
    private CData $local_iov;
    private CData $remote_iov;
    private CData $remote_base;

    /**
     * MemoryReader constructor.
     */
    public function __construct()
    {
        $this->ffi = FFI::cdef('
        typedef int pid_t;
        struct iovec {
            void  *iov_base;    /* Starting address */
            size_t iov_len;     /* Number of bytes to transfer */
        };
        int errno;
        ssize_t process_vm_readv(pid_t pid,
                         const struct iovec *local_iov,
                         unsigned long liovcnt,
                         const struct iovec *remote_iov,
                         unsigned long riovcnt,
                         unsigned long flags);
       ', 'libc.so.6');
        $this->local_iov = $this->ffi->new('struct iovec');
        $this->remote_iov = $this->ffi->new('struct iovec');
        $this->remote_base = $this->ffi->new('long');
    }

    /**
     * @param int $pid
     * @param int $remote_address
     * @param int $size
     * @return \FFI\CArray
     * @throws MemoryReaderException
     */
    public function read(int $pid, int $remote_address, int $size): CData
    {
        /** @var CData $buffer */
        $buffer = $this->ffi->new("unsigned char[{$size}]");

        /**
         * @var FFI\Libc\iovec $this->local_iov
         * @psalm-suppress PropertyTypeCoercion
         */
        $this->local_iov->iov_base = FFI::addr($buffer);
        $this->local_iov->iov_len = $size;

        /** @var FFI\Libc\iovec $this->remote_iov */
        $this->remote_iov->iov_len = $size;
        /** @var FFI\CInteger $this->remote_base */
        $this->remote_base->cdata = $remote_address;
        /** @psalm-suppress PropertyTypeCoercion */
        $this->remote_iov->iov_base = FFI::cast('void *', $this->remote_base);

        /** @var FFI\Libc\process_vm_readv_ffi $this->ffi */
        $read = $this->ffi->process_vm_readv(
            $pid,
            FFI::addr($this->local_iov),
            1,
            FFI::addr($this->remote_iov),
            1,
            0
        );
        if ($read === -1) {
            /** @var int $errno */
            $errno = $this->ffi->errno;
            throw new MemoryReaderException(
                "failed to read memory.remote_address={$remote_address}, errno={$errno}",
                $errno
            );
        }

        /** @var \FFI\CArray */
        return $buffer;
    }
}
