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

/**
 * A decorator that can prefetch memory regions from a target process and serve
 * subsequent reads from local buffers.
 *
 * Supports two prefetch modes:
 * - Single-region prefetch via {@see prefetch()}
 * - Scatter-gather prefetch via {@see prefetchScatterGather()} which reads
 *   multiple non-contiguous regions in a single process_vm_readv syscall,
 *   minimizing the consistency window between them.
 */
final class BufferedMemoryReader implements MemoryReaderInterface
{
    private const MAX_SCATTER_GATHER_REGIONS = 2;

    private FFI $ffi;

    // Two fixed buffer slots instead of an array — avoids per-read foreach/hash overhead
    private ?int $buf0_pid = null;
    private int $buf0_address = 0;
    private int $buf0_size = 0;
    private string $buf0_data = '';

    private ?int $buf1_pid = null;
    private int $buf1_address = 0;
    private int $buf1_size = 0;
    private string $buf1_data = '';

    // Pre-allocated scatter-gather infrastructure (reused across calls)
    private CData $sg_local_iovs;
    private CData $sg_remote_iovs;
    /** @var list<CData> */
    private array $sg_remote_bases;
    private CData $sg_small_buf;
    private ?CData $sg_large_buf = null;
    private int $sg_large_buf_size = 0;

    public function __construct(
        private MemoryReaderInterface $inner,
        private int $max_prefetch_size = 65536, // 64KB: typical VM stack usage + margin
    ) {
        $n = self::MAX_SCATTER_GATHER_REGIONS;
        $this->ffi = FFI::cdef('
            typedef int pid_t;
            struct iovec {
                void  *iov_base;
                size_t iov_len;
            };
            int errno;
            ssize_t process_vm_readv(pid_t pid,
                             const struct iovec *local_iov,
                             unsigned long liovcnt,
                             const struct iovec *remote_iov,
                             unsigned long riovcnt,
                             unsigned long flags);
        ', 'libc.so.6');

        $this->sg_local_iovs = $this->ffi->new("struct iovec[{$n}]")
            ?? throw new CannotAllocateBufferException('cannot allocate iovec');
        $this->sg_remote_iovs = $this->ffi->new("struct iovec[{$n}]")
            ?? throw new CannotAllocateBufferException('cannot allocate iovec');
        $this->sg_remote_bases = [];
        for ($i = 0; $i < $n; $i++) {
            $this->sg_remote_bases[] = $this->ffi->new('long')
                ?? throw new CannotAllocateBufferException('cannot allocate buffer');
        }
        // Small buffer for region 0 (typically 8 bytes for a pointer)
        $this->sg_small_buf = $this->ffi->new('unsigned char[64]')
            ?? throw new CannotAllocateBufferException('cannot allocate buffer');
    }

    /**
     * Bulk-copy a single contiguous region from the target process.
     * Replaces any previously prefetched buffers.
     */
    public function prefetch(int $pid, int $address, int $size): void
    {
        if ($size <= 0 || $size > $this->max_prefetch_size) {
            $this->clearBuffer();
            return;
        }
        $cdata = $this->inner->read($pid, $address, $size);
        $this->buf0_pid = $pid;
        $this->buf0_address = $address;
        $this->buf0_size = $size;
        $this->buf0_data = FFI::string($cdata, $size);
        $this->buf1_pid = null;
    }

    /**
     * Read multiple non-contiguous regions in a single process_vm_readv syscall.
     *
     * All regions are read in one kernel entry, so the consistency window
     * between them is limited to kernel-side page-table walk time (typically
     * sub-microsecond) rather than the round-trip through PHP userspace.
     *
     * Uses pre-allocated iovec arrays and buffers to avoid per-call FFI
     * allocation overhead. Region 0 uses a fixed 64-byte buffer (sufficient
     * for pointer-sized fields). Region 1's buffer is allocated once and
     * reused if the size doesn't grow.
     *
     * @param list<array{address: int, size: int}> $regions max 2 regions
     * @throws MemoryReaderException
     */
    public function prefetchScatterGather(int $pid, array $regions): void
    {
        $this->clearBuffer();

        $count = count($regions);
        if ($count === 0 || $count > self::MAX_SCATTER_GATHER_REGIONS) {
            return;
        }

        $total_size = 0;
        foreach ($regions as $r) {
            $total_size += $r['size'];
        }
        if ($total_size > $this->max_prefetch_size) {
            return;
        }

        // Set up iovecs using pre-allocated structures
        /** @var list<CData> $local_buffers */
        $local_buffers = [];

        for ($i = 0; $i < $count; $i++) {
            $size = $regions[$i]['size'];
            $address = $regions[$i]['address'];

            // Pick the right pre-allocated buffer
            if ($i === 0 && $size <= 64) {
                $buf = $this->sg_small_buf;
            } else {
                // Region 1 (VM stack): reuse large buffer if big enough
                if ($this->sg_large_buf === null || $this->sg_large_buf_size < $size) {
                    $this->sg_large_buf = $this->ffi->new("unsigned char[{$size}]")
                        ?? throw new CannotAllocateBufferException('cannot allocate buffer');
                    $this->sg_large_buf_size = $size;
                }
                $buf = $this->sg_large_buf;
            }
            $local_buffers[] = $buf;

            /** @var \FFI\CInteger $this->sg_remote_bases[$i] */
            $this->sg_remote_bases[$i]->cdata = $address;

            /**
             * @var FFI\Libc\iovec $local_iov
             * @psalm-suppress InaccessibleMethod
             * @psalm-suppress PossiblyInvalidPropertyAssignment
             * @psalm-suppress PropertyTypeCoercion
             */
            $local_iov = $this->sg_local_iovs[$i];
            /** @psalm-suppress PropertyTypeCoercion */
            $local_iov->iov_base = FFI::addr($buf);
            $local_iov->iov_len = $size;

            /**
             * @var FFI\Libc\iovec $remote_iov
             * @psalm-suppress InaccessibleMethod
             * @psalm-suppress PossiblyInvalidPropertyAssignment
             */
            $remote_iov = $this->sg_remote_iovs[$i];
            $remote_iov->iov_len = $size;
            /** @psalm-suppress PropertyTypeCoercion */
            $remote_iov->iov_base = FFI::cast('void *', $this->sg_remote_bases[$i]);
        }

        /**
         * @var FFI\Libc\process_vm_readv_ffi $this->ffi
         * @psalm-suppress InaccessibleMethod
         * @psalm-suppress PossiblyInvalidArgument
         * @psalm-suppress MixedAssignment
         */
        $read = $this->ffi->process_vm_readv(
            $pid,
            FFI::addr($this->sg_local_iovs[0]),
            $count,
            FFI::addr($this->sg_remote_iovs[0]),
            $count,
            0
        );

        if ($read === -1) {
            /** @var int $errno */
            $errno = $this->ffi->errno;
            if ($errno === Errno::ESRCH) {
                throw new ProcessNotFoundException("process not found. pid={$pid}");
            }
            throw new MemoryReaderException(
                "scatter-gather prefetch failed. pid={$pid}, regions={$count}, errno={$errno}",
                $errno
            );
        }

        // Store regions in fixed slots
        $this->buf0_pid = $pid;
        $this->buf0_address = $regions[0]['address'];
        $this->buf0_size = $regions[0]['size'];
        $this->buf0_data = FFI::string($local_buffers[0], $regions[0]['size']);

        if ($count > 1) {
            $this->buf1_pid = $pid;
            $this->buf1_address = $regions[1]['address'];
            $this->buf1_size = $regions[1]['size'];
            $this->buf1_data = FFI::string($local_buffers[1], $regions[1]['size']);
        } else {
            $this->buf1_pid = null;
        }
    }

    public function clearBuffer(): void
    {
        $this->buf0_pid = null;
        $this->buf1_pid = null;
    }

    public function getMaxPrefetchSize(): int
    {
        return $this->max_prefetch_size;
    }

    public function setMaxPrefetchSize(int $max_prefetch_size): void
    {
        $this->max_prefetch_size = $max_prefetch_size;
    }

    /**
     * Fast path: read a 64-bit integer directly from the buffer without
     * any FFI allocation or casting. Returns null on buffer miss.
     */
    public function readRawInt64(int $pid, int $remote_address): ?int
    {
        // Check slot 1 first (VM stack)
        if (
            $this->buf1_pid === $pid
            && $remote_address >= $this->buf1_address
            && ($remote_address + 8) <= ($this->buf1_address + $this->buf1_size)
        ) {
            $offset = $remote_address - $this->buf1_address;
            /** @var array{1: int} $v */
            $v = unpack('P', $this->buf1_data, $offset);
            return $v[1];
        }
        // Check slot 0
        if (
            $this->buf0_pid === $pid
            && $remote_address >= $this->buf0_address
            && ($remote_address + 8) <= ($this->buf0_address + $this->buf0_size)
        ) {
            $offset = $remote_address - $this->buf0_address;
            /** @var array{1: int} $v */
            $v = unpack('P', $this->buf0_data, $offset);
            return $v[1];
        }
        return null;
    }

    /**
     * @return \FFI\CArray<int>
     * @throws MemoryReaderException
     */
    #[\Override]
    public function read(int $pid, int $remote_address, int $size): CData
    {
        // Check slot 1 first (VM stack — most reads hit here)
        if (
            $this->buf1_pid === $pid
            && $remote_address >= $this->buf1_address
            && ($remote_address + $size) <= ($this->buf1_address + $this->buf1_size)
        ) {
            $offset = $remote_address - $this->buf1_address;
            $buffer = $this->ffi->new("unsigned char[{$size}]")
                ?? throw new CannotAllocateBufferException('cannot allocate buffer');
            FFI::memcpy($buffer, substr($this->buf1_data, $offset, $size), $size);
            /** @var \FFI\CArray<int> */
            return $buffer;
        }
        // Check slot 0 (EG field / single prefetch)
        if (
            $this->buf0_pid === $pid
            && $remote_address >= $this->buf0_address
            && ($remote_address + $size) <= ($this->buf0_address + $this->buf0_size)
        ) {
            $offset = $remote_address - $this->buf0_address;
            $buffer = $this->ffi->new("unsigned char[{$size}]")
                ?? throw new CannotAllocateBufferException('cannot allocate buffer');
            FFI::memcpy($buffer, substr($this->buf0_data, $offset, $size), $size);
            /** @var \FFI\CArray<int> */
            return $buffer;
        }
        return $this->inner->read($pid, $remote_address, $size);
    }
}
