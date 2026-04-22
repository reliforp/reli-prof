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

namespace Reli\Lib\PhpProcessReader\CallTraceReader;

use Reli\Lib\FFI\FFIHelper;
use Reli\Lib\PhpInternals\Opcodes\OpcodeFactory;
use Reli\Lib\PhpInternals\Types\C\RawDouble;
use Reli\Lib\PhpInternals\Types\C\RawInt64;
use Reli\Lib\PhpInternals\Types\Zend\Opline;
use Reli\Lib\PhpInternals\Types\Zend\ZendCastedTypeProvider;
use Reli\Lib\PhpInternals\Types\Zend\ZendExecuteData;
use Reli\Lib\PhpInternals\Types\Zend\ZendFunction;
use Reli\Lib\PhpInternals\Types\Zend\ZendOp;
use Reli\Lib\PhpInternals\VersionedPointedTypeResolver;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpInternals\ZendTypeReaderCreator;
use Reli\Lib\Process\MemoryReader\BufferedMemoryReader;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;
use Reli\Lib\Process\MemoryReader\MemoryReaderException;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\FieldReader;
use Reli\Lib\Process\Pointer\LazyDereferencer;
use Reli\Lib\Process\Pointer\Pointer;
use Reli\Lib\Process\Pointer\RemoteProcessDereferencer;
use Reli\Lib\Process\ProcessSpecifier;

final class CallTraceReader
{
    /** Offset of zend_vm_stack->prev within the segment header. */
    private const ZEND_VM_STACK_PREV_OFFSET = 16;
    /** Offset of zend_vm_stack->top within the segment header. */
    private const ZEND_VM_STACK_TOP_OFFSET = 0;

    /**
     * Scatter-gather layout tuning for bulk VM stack prefetch.
     *
     * Linux's process_vm_readv reads iovs in order and within each iov low
     * -> high. To minimize the time gap between capturing EG(current_execute_data)
     * (read first, at t0) and snapshotting the frame it points to, the iov
     * containing the live top frames is placed right after the CED iov so it
     * is read next. The remainder (cold, nearly-immutable deeper frames) is
     * read last.
     *
     * HOT_BELOW_TOP_BYTES: how many bytes below EG(vm_stack_top) to include
     *   in the hot chunk. Must comfortably cover the active frames the chain
     *   walker actually touches (~65 frames at ~128B/frame = 8KB).
     *
     * HOT_ABOVE_TOP_BYTES: margin above vm_stack_top included in the hot
     *   chunk, to catch frames pushed between Step 1 (reading EG fields) and
     *   Step 2 (the actual scatter-gather read).
     */
    private const HOT_BELOW_TOP_BYTES = 8192;
    private const HOT_ABOVE_TOP_BYTES = 16384;

    private ?ZendTypeReader $zend_type_reader = null;
    private bool $bulk_stack_copy_enabled = false;

    /** Base address of the VM stack segment last prefetched, for chain walk lazy-load. */
    private int $current_vm_stack_base = 0;

    public function __construct(
        private MemoryReaderInterface $memory_reader,
        private ZendTypeReaderCreator $zend_type_reader_creator,
        private OpcodeFactory $opcode_factory,
    ) {
    }

    public function enableBulkStackCopy(?int $max_prefetch_size = null): void
    {
        $this->bulk_stack_copy_enabled = true;
        if ($max_prefetch_size !== null && $this->memory_reader instanceof BufferedMemoryReader) {
            $this->memory_reader->setMaxPrefetchSize($max_prefetch_size);
        }
    }

    /**
     * @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     */
    public function getTypeReader(string $php_version): ZendTypeReader
    {
        if (is_null($this->zend_type_reader)) {
            $this->zend_type_reader = $this->zend_type_reader_creator->create($php_version);
        }
        return $this->zend_type_reader;
    }

    /**
     * @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     */
    private function getDereferencer(int $pid, string $php_version): Dereferencer
    {
        $process_specifier = new ProcessSpecifier($pid);
        $type_reader = $this->getTypeReader($php_version);

        $pointed_type_resolver = new VersionedPointedTypeResolver($php_version);

        $base = new RemoteProcessDereferencer(
            $this->memory_reader,
            $process_specifier,
            new ZendCastedTypeProvider($type_reader),
            $pointed_type_resolver,
        );

        return new LazyDereferencer(
            $base,
            new FieldReader($this->memory_reader, $process_specifier, $type_reader, $pointed_type_resolver),
            $pointed_type_resolver,
        );
    }

    /**
     * @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     * @return Pointer<ZendExecuteData>|null
     */
    private function getCurrentExecuteDataPointer(
        int $eg_address,
        string $php_version,
        Dereferencer $dereferencer,
    ): ?Pointer {
        $zend_type_reader = $this->getTypeReader($php_version);
        [$offset, $size] = $zend_type_reader->getOffsetAndSizeOfMember(
            'zend_executor_globals',
            'current_execute_data'
        );
        $pointer = new Pointer(
            RawInt64::class,
            $eg_address + $offset,
            $size
        );
        $raw = $dereferencer->deref($pointer);
        if ($raw->value === 0) {
            return null;
        }
        return new Pointer(
            ZendExecuteData::class,
            $raw->value,
            $zend_type_reader->sizeOf('zend_execute_data'),
        );
    }

    /**
     * @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     */
    public function getGlobalRequestTime(
        int $sg_address,
        string $php_version,
        Dereferencer $dereferencer,
    ): float {
        $zend_type_reader = $this->getTypeReader($php_version);
        [$offset, $size] = $zend_type_reader->getOffsetAndSizeOfMember(
            'sapi_globals_struct',
            'global_request_time'
        );

        $pointer = new Pointer(
            RawDouble::class,
            $sg_address + $offset,
            $size
        );
        return $dereferencer->deref($pointer)->value;
    }

    /**
     * Prefetch EG(current_execute_data) and the VM stack in a single
     * process_vm_readv scatter-gather call.
     *
     * Step 1: Read EG(vm_stack), EG(vm_stack_top), and EG(vm_stack_end)
     *         to determine the VM stack range. We prefetch from vm_stack
     *         to min(vm_stack_top + margin, vm_stack_end). The margin
     *         covers frames that may be pushed between Step 1 and Step 2.
     * Step 2: Scatter-gather read in ONE syscall:
     *           iov[0] = EG(current_execute_data) — 8 bytes
     *           iov[1] = VM stack with margin      — typically ~10-25 KB
     *         This ensures current_execute_data and the VM stack snapshot
     *         are from the same kernel entry, minimizing the consistency
     *         window to sub-microsecond kernel page-walk time.
     *
     * @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     * @return Pointer<ZendExecuteData>|null current_execute_data from the scatter-gather snapshot
     */
    private function prefetchVmStackScatterGather(
        int $pid,
        string $php_version,
        int $eg_address,
        Dereferencer $dereferencer,
    ): ?Pointer {
        assert($this->memory_reader instanceof BufferedMemoryReader);

        $this->memory_reader->clearBuffer();

        $zend_type_reader = $this->getTypeReader($php_version);

        // Step 1: Read EG(vm_stack), EG(vm_stack_top), and EG(vm_stack_end)
        [$vm_stack_offset, $ptr_size] = $zend_type_reader->getOffsetAndSizeOfMember(
            'zend_executor_globals',
            'vm_stack'
        );
        $vm_stack_raw = $dereferencer->deref(new Pointer(
            RawInt64::class,
            $eg_address + $vm_stack_offset,
            $ptr_size,
        ));
        if ($vm_stack_raw->value === 0) {
            return null;
        }
        $vm_stack_address = $vm_stack_raw->value;

        [$vm_stack_top_offset,] = $zend_type_reader->getOffsetAndSizeOfMember(
            'zend_executor_globals',
            'vm_stack_top'
        );
        $vm_stack_top_raw = $dereferencer->deref(new Pointer(
            RawInt64::class,
            $eg_address + $vm_stack_top_offset,
            $ptr_size,
        ));
        if ($vm_stack_top_raw->value === 0) {
            return null;
        }

        [$vm_stack_end_offset,] = $zend_type_reader->getOffsetAndSizeOfMember(
            'zend_executor_globals',
            'vm_stack_end'
        );
        $vm_stack_end_raw = $dereferencer->deref(new Pointer(
            RawInt64::class,
            $eg_address + $vm_stack_end_offset,
            $ptr_size,
        ));
        if ($vm_stack_end_raw->value === 0) {
            return null;
        }

        $stack_regions = self::computeVmStackBulkRegions(
            $vm_stack_address,
            $vm_stack_top_raw->value,
            $vm_stack_end_raw->value,
        );
        $total_stack_size = 0;
        foreach ($stack_regions as $r) {
            $total_stack_size += $r['size'];
        }
        if (
            $total_stack_size <= 0
            || $total_stack_size > $this->memory_reader->getMaxPrefetchSize()
        ) {
            return null;
        }

        // Step 2: Scatter-gather read current_execute_data + VM stack in one syscall.
        // Order: CED first, then HOT chunk (frames near vm_stack_top, where the
        // chain walker starts), then COLD chunk (deeper frames). See the class
        // constants HOT_{BELOW,ABOVE}_TOP_BYTES for the layout rationale.
        [$ced_offset,] = $zend_type_reader->getOffsetAndSizeOfMember(
            'zend_executor_globals',
            'current_execute_data'
        );

        $regions = [['address' => $eg_address + $ced_offset, 'size' => $ptr_size]];
        foreach ($stack_regions as $r) {
            $regions[] = $r;
        }
        $this->memory_reader->prefetchScatterGather($pid, $regions);

        // Read current_execute_data from the scatter-gather buffer
        $ced_cdata = $this->memory_reader->read($pid, $eg_address + $ced_offset, $ptr_size);
        /** @var \FFI\CInteger $cast */
        $cast = FFIHelper::cast('long', $ced_cdata);
        $ced_address = $cast->cdata;
        if ($ced_address === 0) {
            return null;
        }

        // Record the current segment base so the chain walk can lazily follow
        // `zend_vm_stack->prev` into older segments on buffer miss.
        $this->current_vm_stack_base = $vm_stack_address;

        return new Pointer(
            ZendExecuteData::class,
            $ced_address,
            $zend_type_reader->sizeOf('zend_execute_data'),
        );
    }

    /**
     * Build the VM-stack portion of the scatter-gather iov list, split so the
     * kernel reads hot frames (near vm_stack_top) right after the CED iov and
     * cold frames (near <main>) last.
     *
     * Returned regions are ordered for iov placement: hot chunk first, cold
     * chunk second (when a split is needed). For shallow stacks where the hot
     * window already reaches the segment base, a single region is returned.
     *
     * @return list<array{address: int, size: int}>
     */
    public static function computeVmStackBulkRegions(
        int $vm_stack_address,
        int $vm_stack_top,
        int $vm_stack_end,
    ): array {
        $hot_start = max($vm_stack_address, $vm_stack_top - self::HOT_BELOW_TOP_BYTES);
        $hot_end = min($vm_stack_end, $vm_stack_top + self::HOT_ABOVE_TOP_BYTES);
        if ($hot_end <= $hot_start) {
            return [];
        }
        $hot_size = $hot_end - $hot_start;

        if ($hot_start <= $vm_stack_address) {
            // Whole stack fits in the hot window.
            return [['address' => $vm_stack_address, 'size' => $hot_size]];
        }

        $cold_size = $hot_start - $vm_stack_address;
        return [
            ['address' => $hot_start, 'size' => $hot_size],
            ['address' => $vm_stack_address, 'size' => $cold_size],
        ];
    }

    /**
     * Lazily bulk-read a previous VM stack segment into an additional buffer
     * slot so the chain walk can continue following `prev_execute_data`
     * pointers that cross segment boundaries (common with Fibers, whose
     * initial segment is only 4KB).
     *
     * Returns the prev-of-prev segment base address for further chaining,
     * or null on failure / end-of-chain.
     */
    private function loadPrevSegment(
        int $pid,
        int $seg_base_address,
        Dereferencer $dereferencer,
    ): ?int {
        if (!$this->memory_reader instanceof BufferedMemoryReader) {
            return null;
        }

        // Read prev segment's header fields via inner reader (small one-offs).
        // zend_vm_stack layout: { zval *top; zval *end; zend_vm_stack prev; }
        try {
            $top_pointer = new Pointer(
                RawInt64::class,
                $seg_base_address + self::ZEND_VM_STACK_TOP_OFFSET,
                8,
            );
            $seg_top = $dereferencer->deref($top_pointer)->value;
            $prev_pointer = new Pointer(
                RawInt64::class,
                $seg_base_address + self::ZEND_VM_STACK_PREV_OFFSET,
                8,
            );
            $prev_of_prev = $dereferencer->deref($prev_pointer)->value;
        } catch (MemoryReaderException) {
            return null;
        }

        if ($seg_top <= $seg_base_address) {
            return null;
        }
        $data_size = $seg_top - $seg_base_address;
        if ($data_size > $this->memory_reader->getMaxPrefetchSize()) {
            return null;
        }

        if (!$this->memory_reader->prefetchAdditional($pid, $seg_base_address, $data_size)) {
            return null;
        }

        return $prev_of_prev !== 0 ? $prev_of_prev : null;
    }

    /**
     * @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     * @throws MemoryReaderException
     */
    public function readCallTrace(
        int $pid,
        string $php_version,
        int $executor_globals_address,
        int $sapi_globals_address,
        int $depth,
        TraceCache $trace_cache,
    ): ?CallTrace {
        $dereferencer = $this->getDereferencer($pid, $php_version);

        $current_execute_data_pointer = null;
        if ($this->bulk_stack_copy_enabled && $this->memory_reader instanceof BufferedMemoryReader) {
            $current_execute_data_pointer = $this->prefetchVmStackScatterGather(
                $pid,
                $php_version,
                $executor_globals_address,
                $dereferencer,
            );
        }

        if ($current_execute_data_pointer === null) {
            $current_execute_data_pointer = $this->getCurrentExecuteDataPointer(
                $executor_globals_address,
                $php_version,
                $dereferencer,
            );
        }
        if (is_null($current_execute_data_pointer)) {
            return null;
        }

        $trace_cache->updateCacheKey($this->getGlobalRequestTime($sapi_globals_address, $php_version, $dereferencer));
        $cached_dereferencer = $trace_cache->getDereferencer($dereferencer);

        $zend_type_reader = $this->getTypeReader($php_version);
        $ed_size = $zend_type_reader->sizeOf('zend_execute_data');

        // Pre-compute offsets for hot paths (avoids repeated getOffsetAndSizeOfMember)
        [$func_offset,] = $zend_type_reader->getOffsetAndSizeOfMember('zend_execute_data', 'func');
        [$prev_offset,] = $zend_type_reader->getOffsetAndSizeOfMember('zend_execute_data', 'prev_execute_data');
        [$opline_offset,] = $zend_type_reader->getOffsetAndSizeOfMember('zend_execute_data', 'opline');
        $func_size = $zend_type_reader->sizeOf('zend_function');
        $opline_size = $zend_type_reader->sizeOf('zend_op');

        // Fast chain walk: collect frame addresses using raw int64 reads,
        // bypassing LazyDereferencer/FieldReader/Pointer per-frame overhead.
        $buffered = ($this->bulk_stack_copy_enabled && $this->memory_reader instanceof BufferedMemoryReader)
            ? $this->memory_reader
            : null;

        $frame_addresses = [];
        $addr = $current_execute_data_pointer->address;

        // If the top frame has func=NULL, it is being initialized.
        // Skip it and use prev_execute_data as the effective top.
        if ($buffered !== null) {
            $func_val = $buffered->readRawInt64($pid, $addr + $func_offset);
            if ($func_val === 0) {
                $prev_val = $buffered->readRawInt64($pid, $addr + $prev_offset);
                if ($prev_val !== null && $prev_val !== 0) {
                    $addr = $prev_val;
                }
            }
        }

        $frame_addresses[] = $addr;

        // Lazy prev-segment fetch state: when a `prev_execute_data` pointer
        // crosses into an older VM stack segment (common with Fibers, whose
        // initial segment is only 4KB), we issue a one-shot bulk readv for
        // the prev segment's used range so subsequent chain-walk reads hit
        // the buffer instead of falling through to per-field reads.
        $next_seg_base = null;  // base address of the next segment to load lazily; null = unknown / chain end
        if ($buffered !== null && $this->current_vm_stack_base !== 0) {
            $next_seg_base = $buffered->readRawInt64(
                $pid,
                $this->current_vm_stack_base + self::ZEND_VM_STACK_PREV_OFFSET,
            );
            if ($next_seg_base === 0) {
                $next_seg_base = null;
            }
        }

        for ($i = 0; $i < $depth; $i++) {
            /** @var int|null $prev_addr */
            $prev_addr = $buffered?->readRawInt64($pid, $addr + $prev_offset);
            if ($prev_addr === null && $buffered !== null && $next_seg_base !== null) {
                // Buffer miss. Try lazy prev-segment fetch before falling back.
                $next_seg_base = $this->loadPrevSegment($pid, $next_seg_base, $dereferencer);
                $prev_addr = $buffered->readRawInt64($pid, $addr + $prev_offset);
            }
            if ($prev_addr === null) {
                // Fall back to FieldReader for remaining frames
                try {
                    $pointer = new Pointer(ZendExecuteData::class, $addr, $ed_size);
                    $ed = $dereferencer->deref($pointer);
                    if (is_null($ed->prev_execute_data)) {
                        break;
                    }
                    $addr = $ed->prev_execute_data->address;
                    $frame_addresses[] = $addr;
                    // Continue with buffer reads from new address
                    continue;
                } catch (MemoryReaderException) {
                    break;
                }
            }
            if ($prev_addr === 0) {
                break;
            }
            $addr = $prev_addr;
            $frame_addresses[] = $addr;
        }

        // Build lazy ZendExecuteData objects from collected addresses
        $stack = [];
        foreach ($frame_addresses as $frame_addr) {
            $pointer = new Pointer(ZendExecuteData::class, $frame_addr, $ed_size);
            $stack[] = $dereferencer->deref($pointer);
        }

        $result = [];
        foreach ($frame_addresses as $idx => $frame_addr) {
            try {
                $current_execute_data = $stack[$idx];

                // Read func and opline pointers directly from buffer,
                // bypassing __get → getFieldLazy → FieldReader → getOffsetAndSizeOfMember
                $func_ptr = null;
                $opline_ptr = null;
                $func_addr_raw = $buffered?->readRawInt64($pid, $frame_addr + $func_offset);
                if ($func_addr_raw !== null) {
                    $func_ptr = $func_addr_raw !== 0
                        ? new Pointer(ZendFunction::class, $func_addr_raw, $func_size)
                        : null;
                    // Pre-populate the lazy property to avoid __get on subsequent access
                    $current_execute_data->func = $func_ptr;
                } else {
                    $func_ptr = $current_execute_data->func;
                }

                $function_name = $current_execute_data->getFunctionName(
                    $cached_dereferencer,
                    $zend_type_reader,
                );

                if ($func_ptr === null) {
                    $result[] = new CallFrame(
                        '',
                        $function_name,
                        '<unknown>',
                        null
                    );
                    continue;
                }
                $current_function = $cached_dereferencer->deref($func_ptr);

                $class_name = $current_function->getClassName($cached_dereferencer) ?? '';
                $file_name = $current_function->getFileName($cached_dereferencer) ?? '<unknown>';

                $opline = null;
                if (
                    $file_name !== '<internal>'
                    and $file_name !== '<unknown>'
                ) {
                    $opline_addr_raw = $buffered?->readRawInt64($pid, $frame_addr + $opline_offset);
                    if ($opline_addr_raw !== null) {
                        $opline_ptr = $opline_addr_raw !== 0
                            ? new Pointer(ZendOp::class, $opline_addr_raw, $opline_size)
                            : null;
                    } else {
                        $opline_ptr = $current_execute_data->opline;
                    }
                    if ($opline_ptr !== null) {
                        $opline = $this->readOpline(
                            $php_version,
                            $cached_dereferencer->deref($opline_ptr)
                        );
                    }
                }

                $result[] = new CallFrame(
                    $class_name,
                    $function_name,
                    $file_name,
                    $opline
                );
            } catch (MemoryReaderException | \TypeError) {
                // Frame resolution failed — stop here, return what we have.
                // TypeError can occur when FFI CData contains unexpected types
                // (e.g. corrupt frame data from a running target).
                break;
            }
        }

        return $result !== [] ? new CallTrace(...$result) : null;
    }

    /**
     * @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     */
    private function readOpline(string $php_version, ZendOp $zend_op): Opline
    {
        return new Opline(
            0,
            0,
            0,
            0,
            $zend_op->lineno,
            $this->opcode_factory->create($php_version, $zend_op->opcode),
            0,
            0,
            0
        );
    }
}
