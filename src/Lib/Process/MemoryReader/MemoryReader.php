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

namespace Reli\Lib\Process\MemoryReader;

use FFI;
use FFI\CData;
use Reli\Lib\FFI\CannotAllocateBufferException;
use Reli\Lib\Libc\Errno\Errno;
use Reli\Lib\Process\ProcessNotFoundException;

final class MemoryReader implements MemoryReaderInterface
{
    private const BATCH_MAX = 64;

    private FFI $ffi;
    private ?FFI $batch_ffi = null;
    private CData $local_iov;
    private CData $remote_iov;
    private CData $remote_base;

    // Pre-allocated batch buffers
    private CData $batch_addrs;
    private CData $batch_out;

    /** @var array<int, int> address => offset into batch_out */
    private array $batch_cache = [];

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
        $this->local_iov = $this->ffi->new('struct iovec')
            ?? throw new CannotAllocateBufferException('cannot allocate buffer');
        $this->remote_iov = $this->ffi->new('struct iovec')
            ?? throw new CannotAllocateBufferException('cannot allocate buffer');
        $this->remote_base = $this->ffi->new('long')
            ?? throw new CannotAllocateBufferException('cannot allocate buffer');

        $max = self::BATCH_MAX;
        $this->batch_addrs = $this->ffi->new("long[{$max}]")
            ?? throw new CannotAllocateBufferException('cannot allocate buffer');
        $this->batch_out = $this->ffi->new('unsigned char[' . ($max * 8) . ']')
            ?? throw new CannotAllocateBufferException('cannot allocate buffer');

        $batch_lib = dirname(__DIR__, 4) . '/lib/libbatch_readv.so';
        if (file_exists($batch_lib)) {
            $this->batch_ffi = FFI::cdef(
                'int batch_readv_ptrs(int pid, const long *addrs, void *out, int count);',
                $batch_lib
            );
        }
    }

    /**
     * @return \FFI\CArray<int>
     * @throws MemoryReaderException
     */
    #[\Override]
    public function read(int $pid, int $remote_address, int $size): CData
    {
        // Check batch prefetch cache (offset-based, cast on demand)
        if (isset($this->batch_cache[$remote_address])) {
            $offset = $this->batch_cache[$remote_address];
            /** @var \FFI\CArray<int> */
            return FFI::cast("unsigned char[{$size}]", FFI::addr($this->batch_out) + $offset);
        }

        $buffer = $this->ffi->new("unsigned char[{$size}]")
            ?? throw new CannotAllocateBufferException('cannot allocate buffer');

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
            if ($errno === Errno::ESRCH) {
                throw new ProcessNotFoundException("process not found. pid={$pid}");
            }
            $log_address = dechex($remote_address);
            throw new MemoryReaderException(
                "failed to read memory. target_pid={$pid}, remote_address=0x{$log_address}, errno={$errno}",
                $errno
            );
        }

        /** @var \FFI\CArray<int> */
        return $buffer;
    }

    /**
     * Batch-read multiple 8-byte pointer-sized regions in a single syscall.
     * The C helper sets up iovecs internally — zero FFI::cast calls in PHP.
     * Results are accessed lazily via read() with a single cast on demand.
     *
     * @param list<int> $addresses remote addresses to read (each 8 bytes)
     */
    public function readMultiPointers(int $pid, array $addresses): void
    {
        if ($this->batch_ffi === null) {
            return;
        }
        $count = count($addresses);
        if ($count === 0 || $count > self::BATCH_MAX) {
            return;
        }

        // Fill address array: simple integer writes, no FFI::cast
        for ($i = 0; $i < $count; $i++) {
            $this->batch_addrs[$i]->cdata = $addresses[$i];
        }

        // Single C call: iov setup + process_vm_readv all in C
        $read = $this->batch_ffi->batch_readv_ptrs(
            $pid,
            $this->batch_addrs,
            $this->batch_out,
            $count
        );
        if ($read <= 0) {
            return;
        }

        // Store offsets only — no FFI::cast here, deferred to read()
        for ($i = 0; $i < $count; $i++) {
            $this->batch_cache[$addresses[$i]] = $i * 8;
        }
    }

    public function clearBatchCache(): void
    {
        $this->batch_cache = [];
    }
}
