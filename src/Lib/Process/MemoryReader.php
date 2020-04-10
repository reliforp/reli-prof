<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */


namespace PhpProfiler\Lib\Process;


/**
 * Class MemoryReader
 * @package PhpProfiler\Lib\Process
 */
class MemoryReader
{
    private $ffi;

    /**
     * MemoryReader constructor.
     */
    public function __construct()
    {
        $this->ffi = \FFI::cdef('
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
     * @param string $remote_address
     * @param int $size
     * @return mixed
     * @throws MemoryReaderException
     */
    public function read(int $pid, string $remote_address, int $size)
    {
        $buffer = $this->ffi->new("unsigned char[{$size}]");
        $this->local_iov->iov_base = \FFI::addr($buffer);
        $this->local_iov->iov_len = $size;
        $this->remote_iov->iov_len = $size;
        $this->remote_base->cdata = (int)$remote_address;
        $this->remote_iov->iov_base = \FFI::cast('void *', $this->remote_base);
        $read = $this->ffi->process_vm_readv($pid, \FFI::addr($this->local_iov), 1, \FFI::addr($this->remote_iov), 1, 0);
        if ($read === -1) {
            $errno = $this->ffi->errno;
            throw new MemoryReaderException('failed to read memory', $errno);
        }
        return $buffer;
    }

    /**
     * @param int $pid
     * @param string $remote_address
     * @return int
     * @throws MemoryReaderException
     */
    public function readAsInt64(int $pid, string $remote_address): int
    {
        $bytes = $this->read($pid, $remote_address, 8);
        return $bytes[0]
            + ($bytes[1] << 8)
            + ($bytes[2] << 16)
            + ($bytes[3] << 24)
            + ($bytes[4] << 32)
            + ($bytes[5] << 40)
            + ($bytes[6] << 48)
            + ($bytes[7] << 56);
    }
}