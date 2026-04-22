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
use Reli\Lib\FFI\FFIHelper;
use Reli\Lib\Libc\Errno\Errno;
use Reli\Lib\Process\ProcessNotFoundException;

/**
 * A decorator that can prefetch memory regions from a target process and serve
 * subsequent reads from local buffers.
 *
 * Backed by a single pre-allocated pool buffer that is sliced bump-pointer
 * style per prefetch. Up to {@see MAX_SCATTER_GATHER_REGIONS} regions may be
 * kept resident at once, each occupying a slot with its own remote-address
 * bookkeeping. This is enough headroom to cover the current VM stack segment
 * plus a few lazily-fetched prev segments (as in Fiber-heavy targets).
 *
 * Supports:
 * - Single-region prefetch via {@see prefetch()}.
 * - Scatter-gather prefetch via {@see prefetchScatterGather()} that populates
 *   multiple slots from a single process_vm_readv syscall — minimizing the
 *   consistency window between the regions.
 * - Incremental fetch via {@see prefetchAdditional()} that appends one more
 *   region into a free slot without disturbing existing slots (used for
 *   on-demand prev-segment reads during VM stack chain walks).
 */
final class BufferedMemoryReader implements MemoryReaderInterface
{
    public const MAX_SCATTER_GATHER_REGIONS = 4;

    private FFI $ffi;

    /** Pre-allocated pool buffer; all prefetched data lives here. */
    private CData $pool;
    private int $pool_size;
    private int $pool_used = 0;

    /** @var array<int, ?int> */
    private array $slot_pid;
    /** @var array<int, int> */
    private array $slot_address;
    /** @var array<int, int> */
    private array $slot_size;
    /** @var array<int, string> */
    private array $slot_data;

    private CData $sg_local_iovs;
    private CData $sg_remote_iovs;
    /** @var list<CData> */
    private array $sg_remote_bases;

    public function __construct(
        private MemoryReaderInterface $inner,
        private int $max_prefetch_size = 1048576,
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

        $this->pool = $this->ffi->new("unsigned char[{$max_prefetch_size}]")
            ?? throw new CannotAllocateBufferException('cannot allocate pool buffer');
        $this->pool_size = $max_prefetch_size;

        $this->sg_local_iovs = $this->ffi->new("struct iovec[{$n}]")
            ?? throw new CannotAllocateBufferException('cannot allocate iovec');
        $this->sg_remote_iovs = $this->ffi->new("struct iovec[{$n}]")
            ?? throw new CannotAllocateBufferException('cannot allocate iovec');
        $this->sg_remote_bases = [];
        for ($i = 0; $i < $n; $i++) {
            $this->sg_remote_bases[] = $this->ffi->new('long')
                ?? throw new CannotAllocateBufferException('cannot allocate buffer');
        }

        $this->slot_pid = array_fill(0, $n, null);
        $this->slot_address = array_fill(0, $n, 0);
        $this->slot_size = array_fill(0, $n, 0);
        $this->slot_data = array_fill(0, $n, '');
    }

    /**
     * Bulk-copy a single contiguous region from the target process.
     * Replaces any previously prefetched buffers.
     */
    public function prefetch(int $pid, int $address, int $size): void
    {
        $this->clearBuffer();
        if ($size <= 0 || $size > $this->pool_size) {
            return;
        }
        $cdata = $this->inner->read($pid, $address, $size);
        $this->slot_pid[0] = $pid;
        $this->slot_address[0] = $address;
        $this->slot_size[0] = $size;
        $this->slot_data[0] = \FFI::string($cdata, $size);
        $this->pool_used = $size;
    }

    /**
     * Read multiple non-contiguous regions in a single process_vm_readv syscall.
     *
     * All regions are read in one kernel entry, so the consistency window
     * between them is limited to kernel-side page-table walk time (typically
     * sub-microsecond) rather than the round-trip through PHP userspace.
     *
     * Regions are copied into contiguous offsets within the pre-allocated
     * pool, then surfaced as independent slots addressable via the caller's
     * remote address.
     *
     * @param list<array{address: int, size: int}> $regions max MAX_SCATTER_GATHER_REGIONS
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
        if ($total_size > $this->pool_size) {
            return;
        }

        $offsets = [];
        $cursor = 0;
        for ($i = 0; $i < $count; $i++) {
            $size = $regions[$i]['size'];
            $address = $regions[$i]['address'];
            $offsets[$i] = $cursor;

            /** @var \FFI\CInteger $this->sg_remote_bases[$i] */
            $this->sg_remote_bases[$i]->cdata = $address;

            /**
             * @var FFI\Libc\iovec $local_iov
             * @psalm-suppress InaccessibleMethod
             * @psalm-suppress PossiblyInvalidPropertyAssignment
             * @psalm-suppress PropertyTypeCoercion
             */
            $local_iov = $this->sg_local_iovs[$i];
            /**
             * @psalm-suppress PropertyTypeCoercion
             * @psalm-suppress InaccessibleMethod
             * @psalm-suppress PossiblyInvalidArgument
             */
            $local_iov->iov_base = \FFI::addr($this->pool[$cursor]);
            $local_iov->iov_len = $size;

            /**
             * @var FFI\Libc\iovec $remote_iov
             * @psalm-suppress InaccessibleMethod
             * @psalm-suppress PossiblyInvalidPropertyAssignment
             */
            $remote_iov = $this->sg_remote_iovs[$i];
            $remote_iov->iov_len = $size;
            /** @psalm-suppress PropertyTypeCoercion */
            $remote_iov->iov_base = FFIHelper::cast('void *', $this->sg_remote_bases[$i]);

            $cursor += $size;
        }

        /**
         * @var FFI\Libc\process_vm_readv_ffi $this->ffi
         * @psalm-suppress InaccessibleMethod
         * @psalm-suppress PossiblyInvalidArgument
         * @psalm-suppress MixedAssignment
         */
        $read = $this->ffi->process_vm_readv(
            $pid,
            \FFI::addr($this->sg_local_iovs[0]),
            $count,
            \FFI::addr($this->sg_remote_iovs[0]),
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

        for ($i = 0; $i < $count; $i++) {
            $size = $regions[$i]['size'];
            $this->slot_pid[$i] = $pid;
            $this->slot_address[$i] = $regions[$i]['address'];
            $this->slot_size[$i] = $size;
            /**
             * @psalm-suppress InaccessibleMethod
             * @psalm-suppress PossiblyInvalidArgument
             */
            $this->slot_data[$i] = \FFI::string(\FFI::addr($this->pool[$offsets[$i]]), $size);
        }
        $this->pool_used = $cursor;
    }

    /**
     * Append a region into the next free slot WITHOUT disturbing existing slots.
     *
     * Used to lazily fetch prev VM stack segments mid-chain-walk. Issues its
     * own single-region process_vm_readv (so the new region is not guaranteed
     * consistent with the earlier prefetched regions — that is acceptable for
     * colder, older-segment frames whose content is essentially frozen during
     * a request).
     *
     * Returns true iff the data is now resident in the buffer.
     *
     * @throws MemoryReaderException
     */
    public function prefetchAdditional(int $pid, int $address, int $size): bool
    {
        if ($size <= 0) {
            return false;
        }
        if ($this->pool_used + $size > $this->pool_size) {
            return false;
        }

        $slot = -1;
        for ($i = 0; $i < self::MAX_SCATTER_GATHER_REGIONS; $i++) {
            if ($this->slot_pid[$i] === null) {
                $slot = $i;
                break;
            }
        }
        if ($slot < 0) {
            return false;
        }

        $offset = $this->pool_used;

        /** @var \FFI\CInteger $this->sg_remote_bases[0] */
        $this->sg_remote_bases[0]->cdata = $address;

        /**
         * @var FFI\Libc\iovec $local_iov
         * @psalm-suppress InaccessibleMethod
         * @psalm-suppress PossiblyInvalidPropertyAssignment
         * @psalm-suppress PropertyTypeCoercion
         */
        $local_iov = $this->sg_local_iovs[0];
        /**
         * @psalm-suppress PropertyTypeCoercion
         * @psalm-suppress InaccessibleMethod
         * @psalm-suppress PossiblyInvalidArgument
         */
        $local_iov->iov_base = \FFI::addr($this->pool[$offset]);
        $local_iov->iov_len = $size;

        /**
         * @var FFI\Libc\iovec $remote_iov
         * @psalm-suppress InaccessibleMethod
         * @psalm-suppress PossiblyInvalidPropertyAssignment
         */
        $remote_iov = $this->sg_remote_iovs[0];
        $remote_iov->iov_len = $size;
        /** @psalm-suppress PropertyTypeCoercion */
        $remote_iov->iov_base = FFIHelper::cast('void *', $this->sg_remote_bases[0]);

        /**
         * @var FFI\Libc\process_vm_readv_ffi $this->ffi
         * @psalm-suppress InaccessibleMethod
         * @psalm-suppress PossiblyInvalidArgument
         * @psalm-suppress MixedAssignment
         */
        $read = $this->ffi->process_vm_readv(
            $pid,
            \FFI::addr($this->sg_local_iovs[0]),
            1,
            \FFI::addr($this->sg_remote_iovs[0]),
            1,
            0
        );

        if ($read === -1) {
            /** @var int $errno */
            $errno = $this->ffi->errno;
            if ($errno === Errno::ESRCH) {
                throw new ProcessNotFoundException("process not found. pid={$pid}");
            }
            return false;
        }

        $this->slot_pid[$slot] = $pid;
        $this->slot_address[$slot] = $address;
        $this->slot_size[$slot] = $size;
        /**
         * @psalm-suppress InaccessibleMethod
         * @psalm-suppress PossiblyInvalidArgument
         */
        $this->slot_data[$slot] = \FFI::string(\FFI::addr($this->pool[$offset]), $size);
        $this->pool_used = $offset + $size;

        return true;
    }

    public function clearBuffer(): void
    {
        for ($i = 0; $i < self::MAX_SCATTER_GATHER_REGIONS; $i++) {
            $this->slot_pid[$i] = null;
        }
        $this->pool_used = 0;
    }

    public function getMaxPrefetchSize(): int
    {
        return $this->max_prefetch_size;
    }

    /**
     * Grow the pool if the new cap exceeds the current pool size. Shrinking
     * is a no-op so long-lived readers don't churn allocations.
     */
    public function setMaxPrefetchSize(int $max_prefetch_size): void
    {
        $this->max_prefetch_size = $max_prefetch_size;
        if ($max_prefetch_size > $this->pool_size) {
            $this->clearBuffer();
            $this->pool = $this->ffi->new("unsigned char[{$max_prefetch_size}]")
                ?? throw new CannotAllocateBufferException('cannot allocate pool buffer');
            $this->pool_size = $max_prefetch_size;
        }
    }

    /**
     * Fast path: read a 64-bit integer directly from any slot's buffer without
     * any FFI allocation or casting. Returns null on buffer miss.
     */
    public function readRawInt64(int $pid, int $remote_address): ?int
    {
        for ($i = 0; $i < self::MAX_SCATTER_GATHER_REGIONS; $i++) {
            if (
                $this->slot_pid[$i] === $pid
                && $remote_address >= $this->slot_address[$i]
                && ($remote_address + 8) <= ($this->slot_address[$i] + $this->slot_size[$i])
            ) {
                $offset = $remote_address - $this->slot_address[$i];
                /** @var array{1: int} $v */
                $v = unpack('P', $this->slot_data[$i], $offset);
                return $v[1];
            }
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
        for ($i = 0; $i < self::MAX_SCATTER_GATHER_REGIONS; $i++) {
            if (
                $this->slot_pid[$i] === $pid
                && $remote_address >= $this->slot_address[$i]
                && ($remote_address + $size) <= ($this->slot_address[$i] + $this->slot_size[$i])
            ) {
                $offset = $remote_address - $this->slot_address[$i];
                $buffer = $this->ffi->new("unsigned char[{$size}]")
                    ?? throw new CannotAllocateBufferException('cannot allocate buffer');
                \FFI::memcpy($buffer, substr($this->slot_data[$i], $offset, $size), $size);
                /** @var \FFI\CArray<int> */
                return $buffer;
            }
        }
        return $this->inner->read($pid, $remote_address, $size);
    }
}
