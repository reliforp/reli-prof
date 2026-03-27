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

/**
 * A decorator that can prefetch a contiguous memory region (e.g. the VM stack)
 * in a single process_vm_readv call and serve subsequent reads from the local
 * buffer when the requested address falls within the prefetched range.
 *
 * This shrinks the consistency window from thousands of microseconds (many
 * individual readv calls) down to a few microseconds (one bulk read), making
 * it possible to obtain consistent stack snapshots without stopping the target
 * process via PTRACE_ATTACH.
 */
final class BufferedMemoryReader implements MemoryReaderInterface
{
    private FFI $ffi;
    private ?int $buffer_pid = null;
    private int $buffer_address = 0;
    private int $buffer_size = 0;
    private string $buffer_data = '';

    public function __construct(
        private MemoryReaderInterface $inner,
        private int $max_prefetch_size = 65536,
    ) {
        $this->ffi = FFI::cdef('');
    }

    /**
     * Bulk-copy a contiguous region from the target process into a local buffer.
     * Subsequent read() calls for addresses within this region will be served
     * from the buffer without issuing a syscall.
     */
    public function prefetch(int $pid, int $address, int $size): void
    {
        if ($size <= 0 || $size > $this->max_prefetch_size) {
            $this->clearBuffer();
            return;
        }
        $cdata = $this->inner->read($pid, $address, $size);
        $this->buffer_pid = $pid;
        $this->buffer_address = $address;
        $this->buffer_size = $size;
        $this->buffer_data = FFI::string($cdata, $size);
    }

    public function clearBuffer(): void
    {
        $this->buffer_pid = null;
        $this->buffer_address = 0;
        $this->buffer_size = 0;
        $this->buffer_data = '';
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
     * @return \FFI\CArray<int>
     * @throws MemoryReaderException
     */
    #[\Override]
    public function read(int $pid, int $remote_address, int $size): CData
    {
        if (
            $this->buffer_pid === $pid
            && $remote_address >= $this->buffer_address
            && ($remote_address + $size) <= ($this->buffer_address + $this->buffer_size)
        ) {
            $offset = $remote_address - $this->buffer_address;
            $buffer = $this->ffi->new("unsigned char[{$size}]")
                ?? throw new CannotAllocateBufferException('cannot allocate buffer');
            FFI::memcpy($buffer, substr($this->buffer_data, $offset, $size), $size);
            /** @var \FFI\CArray<int> */
            return $buffer;
        }
        return $this->inner->read($pid, $remote_address, $size);
    }
}
