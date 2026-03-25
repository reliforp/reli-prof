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

use FFI;
use Reli\Lib\PhpInternals\Opcodes\OpcodeFactory;
use Reli\Lib\PhpInternals\Types\C\RawDouble;
use Reli\Lib\PhpInternals\Types\C\RawInt64;
use Reli\Lib\PhpInternals\Types\Zend\Opline;
use Reli\Lib\PhpInternals\Types\Zend\ZendCastedTypeProvider;
use Reli\Lib\PhpInternals\Types\Zend\ZendExecuteData;
use Reli\Lib\PhpInternals\Types\Zend\ZendOp;
use Reli\Lib\PhpInternals\VersionedPointedTypeResolver;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpInternals\ZendTypeReaderCreator;
use Reli\Lib\Process\MemoryReader\MemoryReader;
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

    public function __construct(
        private MemoryReaderInterface $memory_reader,
        private ZendTypeReaderCreator $zend_type_reader_creator,
        private OpcodeFactory $opcode_factory,
    ) {
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
        if ($this->memory_reader instanceof MemoryReader) {
            $this->memory_reader->clearBatchCache();
        }

        $dereferencer = $this->getDereferencer($pid, $php_version);
        $current_execute_data_pointer = $this->getCurrentExecuteDataPointer(
            $executor_globals_address,
            $php_version,
            $dereferencer,
        );
        if (is_null($current_execute_data_pointer)) {
            return null;
        }

        $trace_cache->updateCacheKey($this->getGlobalRequestTime($sapi_globals_address, $php_version, $dereferencer));
        $cached_dereferencer = $trace_cache->getDereferencer($dereferencer);

        $current_execute_data = $dereferencer->deref($current_execute_data_pointer);

        $stack = [];
        $stack[] = $current_execute_data;
        for ($i = 0; $i < $depth; $i++) {
            if (is_null($current_execute_data->prev_execute_data)) {
                break;
            }
            $current_execute_data = $dereferencer->deref($current_execute_data->prev_execute_data);
            $stack[] = $current_execute_data;
        }

        try {
            $this->prefetchFrameData($pid, $php_version, $stack);
        } catch (\Throwable) {
            if ($this->memory_reader instanceof MemoryReader) {
                $this->memory_reader->clearBatchCache();
            }
        }

        $result = [];
        foreach ($stack as $current_execute_data) {
            $function_name = $current_execute_data->getFunctionName(
                $cached_dereferencer,
                $this->getTypeReader($php_version),
            );

            if (is_null($current_execute_data->func)) {
                $result[] = new CallFrame(
                    '',
                    $function_name,
                    '<unknown>',
                    null
                );
                continue;
            }
            $current_function = $cached_dereferencer->deref($current_execute_data->func);

            $class_name = $current_function->getClassName($cached_dereferencer) ?? '';
            $file_name = $current_function->getFileName($cached_dereferencer) ?? '<unknown>';

            $opline = null;
            if (
                $file_name !== '<internal>'
                and $file_name !== '<unknown>'
                and !is_null($current_execute_data->opline)
            ) {
                $opline = $this->readOpline(
                    $php_version,
                    $cached_dereferencer->deref($current_execute_data->opline)
                );
            }

            $result[] = new CallFrame(
                $class_name,
                $function_name,
                $file_name,
                $opline
            );
        }

        return new CallTrace(...$result);
    }

    /**
     * Batch-prefetch pointer fields from all execute_data frames.
     * Uses C helper for iov setup — PHP side does zero FFI::cast in readMultiPointers,
     * and only defers a single cast per cache hit in read().
     *
     * @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     * @param ZendExecuteData[] $stack
     */
    private function prefetchFrameData(int $pid, string $php_version, array $stack): void
    {
        if (!($this->memory_reader instanceof MemoryReader)) {
            return;
        }
        $mr = $this->memory_reader;
        $tr = $this->getTypeReader($php_version);

        [$func_off,] = $tr->getOffsetAndSizeOfMember('zend_execute_data', 'func');
        [$opline_off,] = $tr->getOffsetAndSizeOfMember('zend_execute_data', 'opline');
        [$fn_name_off,] = $tr->getOffsetAndSizeOfMember('zend_op_array', 'function_name');
        [$fn_scope_off,] = $tr->getOffsetAndSizeOfMember('zend_op_array', 'scope');
        [$fn_file_off,] = $tr->getOffsetAndSizeOfMember('zend_op_array', 'filename');
        [$ce_name_off,] = $tr->getOffsetAndSizeOfMember('zend_class_entry', 'name');

        // Round 1: func + opline pointers from all frames
        $addrs = [];
        foreach ($stack as $ed) {
            $base = $ed->getPointer()->address;
            $addrs[] = $base + $func_off;
            $addrs[] = $base + $opline_off;
        }
        $mr->readMultiPointers($pid, $addrs);

        // Round 2: parse func addrs (now cached, cast deferred to read()),
        // then batch-read function metadata
        $func_addrs = [];
        $addrs2 = [];
        foreach ($stack as $i => $ed) {
            $buf = $mr->read($pid, $ed->getPointer()->address + $func_off, 8);
            /** @var \FFI\CInteger $ptr */
            $ptr = FFI::cast('long', $buf);
            $fa = $ptr->cdata;
            $func_addrs[$i] = $fa;
            if ($fa !== 0) {
                $addrs2[] = $fa + $fn_name_off;
                $addrs2[] = $fa + $fn_scope_off;
                $addrs2[] = $fa + $fn_file_off;
            }
        }
        if ($addrs2 !== []) {
            $mr->readMultiPointers($pid, $addrs2);
        }

        // Round 3: parse string/class pointers, prefetch class entry name pointers
        $addrs3 = [];
        foreach ($func_addrs as $fa) {
            if ($fa === 0) {
                continue;
            }
            $buf = $mr->read($pid, $fa + $fn_scope_off, 8);
            /** @var \FFI\CInteger $ptr */
            $ptr = FFI::cast('long', $buf);
            $scope_addr = $ptr->cdata;
            if ($scope_addr !== 0) {
                $addrs3[] = $scope_addr + $ce_name_off;
            }
        }
        if ($addrs3 !== []) {
            $mr->readMultiPointers($pid, $addrs3);
        }
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
