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
    private ?ZendTypeReader $zend_type_reader = null;
    private bool $bulk_stack_copy_enabled = false;

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

        // Prefetch up to vm_stack_top + 16KB margin, capped at vm_stack_end.
        // The margin covers frames that may be pushed between Step 1 and Step 2.
        $margin = 16384; // 16KB ≈ ~130 extra frames of headroom
        $prefetch_end = min(
            $vm_stack_top_raw->value + $margin,
            $vm_stack_end_raw->value,
        );
        $stack_size = $prefetch_end - $vm_stack_address;
        if ($stack_size <= 0 || $stack_size > $this->memory_reader->getMaxPrefetchSize()) {
            return null;
        }

        // Step 2: Scatter-gather read current_execute_data + VM stack in one syscall
        [$ced_offset,] = $zend_type_reader->getOffsetAndSizeOfMember(
            'zend_executor_globals',
            'current_execute_data'
        );

        $this->memory_reader->prefetchScatterGather($pid, [
            ['address' => $eg_address + $ced_offset, 'size' => $ptr_size],
            ['address' => $vm_stack_address, 'size' => $stack_size],
        ]);

        // Read current_execute_data from the scatter-gather buffer
        $ced_cdata = $this->memory_reader->read($pid, $eg_address + $ced_offset, $ptr_size);
        /** @var \FFI\CInteger $cast */
        $cast = \FFI::cast('long', $ced_cdata);
        $ced_address = $cast->cdata;
        if ($ced_address === 0) {
            return null;
        }

        return new Pointer(
            ZendExecuteData::class,
            $ced_address,
            $zend_type_reader->sizeOf('zend_execute_data'),
        );
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
                $pid, $php_version, $executor_globals_address, $dereferencer,
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

        for ($i = 0; $i < $depth; $i++) {
            $prev_addr = $buffered?->readRawInt64($pid, $addr + $prev_offset);
            if ($prev_addr === null) {
                // Buffer miss — fall back to FieldReader for remaining frames
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
            } catch (MemoryReaderException) {
                // Frame resolution failed — stop here, return what we have
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
