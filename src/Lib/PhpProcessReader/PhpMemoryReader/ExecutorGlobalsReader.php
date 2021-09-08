<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Lib\PhpProcessReader\PhpMemoryReader;

use FFI\CData;
use PhpProfiler\Lib\ByteStream\CDataByteReader;
use PhpProfiler\Lib\ByteStream\IntegerByteSequence\LittleEndianReader;
use PhpProfiler\Lib\PhpInternals\Opcodes\OpcodeFactory;
use PhpProfiler\Lib\PhpInternals\Types\Zend\Opline;
use PhpProfiler\Lib\PhpInternals\ZendTypeCData;
use PhpProfiler\Lib\PhpInternals\ZendTypeReader;
use PhpProfiler\Lib\PhpInternals\ZendTypeReaderCreator;
use PhpProfiler\Lib\PhpProcessReader\CallFrame;
use PhpProfiler\Lib\PhpProcessReader\CallTrace;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderInterface;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderException;

final class ExecutorGlobalsReader
{
    private ?ZendTypeReader $zend_type_reader = null;

    public function __construct(
        private MemoryReaderInterface $memory_reader,
        private ZendTypeReaderCreator $zend_type_reader_creator,
        private LittleEndianReader $little_endian_reader,
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
     * @throws MemoryReaderException
     */
    public function readCurrentFunctionName(int $pid, string $php_version, int $executor_globals_address): string
    {
        $eg = $this->readExecutorGlobals($pid, $php_version, $executor_globals_address);
        $current_execute_data = $this->readCurrentExecuteData($pid, $php_version, $eg->typed);
        if (is_null($current_execute_data)) {
            throw new \Exception('cannot read current execute data');
        }
        $current_function = $this->readCurrentFunction($pid, $php_version, $current_execute_data->typed);
        if (is_null($current_function)) {
            throw new \Exception('cannot read current function');
        }

        return join(
            '::',
            array_filter(
                $this->readFunctionName($pid, $php_version, $current_function->typed),
                fn (string $item) => strlen($item)
            )
        );
    }

    /**
     * @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     * @return ZendTypeCData<\FFI\PhpInternals\zend_executor_globals>
     * @throws MemoryReaderException
     */
    public function readExecutorGlobals(int $pid, string $php_version, int $executor_globals_address): ZendTypeCData
    {
        $eg_raw = $this->memory_reader->read(
            $pid,
            $executor_globals_address,
            $this->getTypeReader($php_version)->sizeOf('zend_executor_globals')
        );
        /** @var ZendTypeCData<\FFI\PhpInternals\zend_executor_globals> */
        return $this->getTypeReader($php_version)->readAs('zend_executor_globals', $eg_raw);
    }

    /**
     * @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     * @param \FFI\PhpInternals\zend_executor_globals $eg
     * @return null|ZendTypeCData<\FFI\PhpInternals\zend_execute_data>
     * @throws MemoryReaderException
     */
    public function readCurrentExecuteData(int $pid, string $php_version, CData $eg): ?ZendTypeCData
    {
        /** @var \FFI\CPointer $current_execute_data_addr */
        $current_execute_data_addr = \FFI::cast('long', $eg->current_execute_data);
        if ($current_execute_data_addr->cdata === 0) {
            return null;
        }
        $current_execute_data_raw = $this->memory_reader->read(
            $pid,
            $current_execute_data_addr->cdata,
            $this->getTypeReader($php_version)->sizeOf('zend_execute_data')
        );
        /** @var null|ZendTypeCData<\FFI\PhpInternals\zend_execute_data> */
        return $this->getTypeReader($php_version)->readAs('zend_execute_data', $current_execute_data_raw);
    }

    /**
     * @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     * @param \FFI\PhpInternals\zend_execute_data $current_execute_data
     * @return null|ZendTypeCData<\FFI\PhpInternals\zend_function>
     * @throws MemoryReaderException
     */
    public function readCurrentFunction(int $pid, string $php_version, CData $current_execute_data): ?ZendTypeCData
    {
        /** @var \FFI\CPointer $func_pointer */
        $func_pointer = \FFI::cast('long', $current_execute_data->func);
        if ($func_pointer->cdata === 0) {
            return null;
        }
        $current_function_raw = $this->memory_reader->read(
            $pid,
            $func_pointer->cdata,
            $this->getTypeReader($php_version)->sizeOf('zend_function')
        );
        /** @var null|ZendTypeCData<\FFI\PhpInternals\zend_function> */
        return $this->getTypeReader($php_version)->readAs('zend_function', $current_function_raw);
    }

    /**
     * @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     * @return array{string, string}
     * @throws MemoryReaderException
     */
    public function readFunctionName(int $pid, string $php_version, CData $current_function): array
    {
        $function_name = '<main>';
        $class_name = '';
        /**
         * @psalm-var \FFI\PhpInternals\zend_function $current_function
         * @psalm-var \FFI\CPointer $current_function_name_pointer
         */
        $current_function_name_pointer = \FFI::cast('long', $current_function->common->function_name);
        if ($current_function_name_pointer->cdata !== 0) {
            /**
             * @psalm-ignore-var
             * @var \FFI\CPointer $current_function_name_pointer
             */
            $current_function_name_zstring = $this->memory_reader->read(
                $pid,
                $current_function_name_pointer->cdata,
                $this->getTypeReader($php_version)->sizeOf('zend_string') + 256
            );
            /** @var ZendTypeCData<\FFI\PhpInternals\zend_string> $string */
            $string = $this->getTypeReader($php_version)->readAs('zend_string', $current_function_name_zstring);
            $function_name = \FFI::string($string->typed->val);
            /** @var \FFI\CPointer $current_function_scope_pointer */
            $current_function_scope_pointer = \FFI::cast('long', $current_function->common->scope);
            if ($current_function_scope_pointer->cdata !== 0) {
                $current_function_class_entry = $this->memory_reader->read(
                    $pid,
                    $current_function_scope_pointer->cdata,
                    $this->getTypeReader($php_version)->sizeOf('zend_class_entry')
                );
                /** @var ZendTypeCData<\FFI\PhpInternals\zend_class_entry> $class_entry */
                $class_entry = $this->getTypeReader($php_version)
                    ->readAs('zend_class_entry', $current_function_class_entry);
                $current_class_name_pointer = \FFI::cast('long', $class_entry->typed->name);
                /** @var \FFI\CPointer $current_class_name_pointer */
                $current_class_name_zstring = $this->memory_reader->read(
                    $pid,
                    $current_class_name_pointer->cdata,
                    $this->getTypeReader($php_version)->sizeOf('zend_string') + 256
                );
                /** @var ZendTypeCData<\FFI\PhpInternals\zend_string> $class_name_string */
                $class_name_string = $this->getTypeReader($php_version)
                    ->readAs('zend_string', $current_class_name_zstring);
                $class_name = \FFI::string($class_name_string->typed->val);
            }
        }

        return [$class_name, $function_name];
    }

    /**
     * @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     * @throws MemoryReaderException
     */
    public function readFunctionFile(int $pid, string $php_version, CData $current_function): string
    {
        /** @psalm-var \FFI\PhpInternals\zend_function $current_function */
        $filename = '<internal>';
        if ($current_function->type === 2) {
            $filename_pointer = \FFI::cast('long', $current_function->op_array->filename);
            /** @var \FFI\CPointer $filename_pointer */
            $filename_zstring_raw = $this->memory_reader->read(
                $pid,
                $filename_pointer->cdata,
                $this->getTypeReader($php_version)->sizeOf('zend_string') + 256
            );
            /** @var ZendTypeCData<\FFI\PhpInternals\zend_string> $filename_zstring */
            $filename_zstring = $this->getTypeReader($php_version)->readAs('zend_string', $filename_zstring_raw);
            $filename = \FFI::string($filename_zstring->typed->val);
        }
        return $filename;
    }

    /**
     * @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     * @throws MemoryReaderException
     */
    public function readCallTrace(int $pid, string $php_version, int $executor_globals_address, int $depth): ?CallTrace
    {
        $eg = $this->readExecutorGlobals($pid, $php_version, $executor_globals_address);

        $current_execute_data = $this->readCurrentExecuteData($pid, $php_version, $eg->typed);

        if (is_null($current_execute_data)) {
            return null;
        }

        $stack = [];
        $stack[] = $current_execute_data;
        for ($i = 0; $i < $depth; $i++) {
            $current_execute_data = $this->readPreviousExecuteData($pid, $php_version, $current_execute_data->typed);
            if (is_null($current_execute_data)) {
                break;
            }
            $stack[] = $current_execute_data;
        }

        $result = [];
        foreach ($stack as $current_execute_data) {
            $current_function = $this->readCurrentFunction($pid, $php_version, $current_execute_data->typed);
            if (is_null($current_function)) {
                $result[] = new CallFrame(
                    '',
                    '<unknown>',
                    '<unknown>',
                    null
                );
                continue;
            }
            $opline = null;
            $file = $this->readFunctionFile($pid, $php_version, $current_function->typed);
            if ($file !== '<internal>') {
                $opline = $this->readOpline($pid, $php_version, $current_execute_data->typed);
            }
            [$class_name, $function_name] = $this->readFunctionName($pid, $php_version, $current_function->typed);
            $result[] = new CallFrame(
                $class_name,
                $function_name,
                $file,
                $opline
            );
        }

        return new CallTrace(...$result);
    }

    /**
     * @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     * @param \FFI\PhpInternals\zend_execute_data $execute_data
     * @return ZendTypeCData<\FFI\PhpInternals\zend_execute_data>
     * @throws MemoryReaderException
     */
    public function readPreviousExecuteData(int $pid, string $php_version, CData $execute_data): ?ZendTypeCData
    {
        /** @var \FFI\CPointer $previous_execute_data_addr */
        $previous_execute_data_addr = \FFI::cast('long', $execute_data->prev_execute_data);
        if ($previous_execute_data_addr->cdata == 0) {
            return null;
        }
        $previous_execute_data_raw = $this->memory_reader->read(
            $pid,
            $previous_execute_data_addr->cdata,
            $this->getTypeReader($php_version)->sizeOf('zend_execute_data')
        );
        /** @var ZendTypeCData<\FFI\PhpInternals\zend_execute_data> */
        return $this->getTypeReader($php_version)->readAs('zend_execute_data', $previous_execute_data_raw);
    }

    /**
     * @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     * @throws MemoryReaderException
     */
    public function readOpline(int $pid, string $php_version, CData $current_execute_data): ?Opline
    {
        /**
         * @psalm-var \FFI\CPointer $opline_addr
         * @psalm-var \FFI\PhpInternals\zend_execute_data $current_execute_data
         */
        $opline_addr = \FFI::cast('long', $current_execute_data->opline);
        if ($opline_addr->cdata == 0) {
            return null;
        }
        $opline_raw = $this->memory_reader->read(
            $pid,
            $opline_addr->cdata + 8,
            24
        );
        $cdata_reader = new CDataByteReader($opline_raw);
        $op1 = $this->little_endian_reader->read32($cdata_reader, 0);
        $op2 = $this->little_endian_reader->read32($cdata_reader, 4);
        $result = $this->little_endian_reader->read32($cdata_reader, 8);
        $extended_value = $this->little_endian_reader->read32($cdata_reader, 12);
        $lineno = $this->little_endian_reader->read32($cdata_reader, 16);
        $opcode = $this->little_endian_reader->read8($cdata_reader, 20);
        $op1_type = $this->little_endian_reader->read8($cdata_reader, 21);
        $op2_type = $this->little_endian_reader->read8($cdata_reader, 22);
        $result_type = $this->little_endian_reader->read8($cdata_reader, 23);

        return new Opline(
            $op1,
            $op2,
            $result,
            $extended_value,
            $lineno,
            $this->opcode_factory->create($php_version, $opcode),
            $op1_type,
            $op2_type,
            $result_type
        );
    }
}
