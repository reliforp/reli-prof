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
use PhpProfiler\Lib\PhpInternals\ZendTypeReader;
use PhpProfiler\Lib\PhpInternals\ZendTypeReaderCreator;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderInterface;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderException;

/**
 * Class ExecutorGlobalsReader
 * @package PhpProfiler\ProcessReader\PhpStateReader
 */
final class ExecutorGlobalsReader
{
    private MemoryReaderInterface $memory_reader;
    private ZendTypeReaderCreator $zend_type_reader_creator;
    private ?ZendTypeReader $zend_type_reader = null;

    /**
     * ExecutorGlobalsReader constructor.
     * @param MemoryReaderInterface $memory_reader
     * @param ZendTypeReaderCreator $zend_type_reader_creator
     */
    public function __construct(MemoryReaderInterface $memory_reader, ZendTypeReaderCreator $zend_type_reader_creator)
    {
        $this->memory_reader = $memory_reader;
        $this->zend_type_reader_creator = $zend_type_reader_creator;
    }

    /**
     * @param string $php_version
     * @psalm-param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     * @return ZendTypeReader
     */
    public function getTypeReader(string $php_version): ZendTypeReader
    {
        if (is_null($this->zend_type_reader)) {
            $this->zend_type_reader = $this->zend_type_reader_creator->create($php_version);
        }
        return $this->zend_type_reader;
    }

    /**
     * @param int $pid
     * @param string $php_version
     * @param int $executor_globals_address
     * @return string
     * @psalm-param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     * @throws MemoryReaderException
     */
    public function readCurrentFunctionName(int $pid, string $php_version, int $executor_globals_address): string
    {
        /** @var \FFI\PhpInternals\zend_executor_globals $eg */
        $eg = $this->readExecutorGlobals($pid, $php_version, $executor_globals_address);

        /** @var \FFI\PhpInternals\zend_execute_data $current_execute_data */
        $current_execute_data = $this->readCurrentExecuteData($pid, $php_version, $eg);

        /** @var \FFI\PhpInternals\zend_function $current_function */
        $current_function = $this->readCurrentFunction($pid, $php_version, $current_execute_data);

        return $this->readFunctionName($pid, $php_version, $current_function);
    }

    /**
     * @param int $pid
     * @param string $php_version
     * @param int $executor_globals_address
     * @return CData
     * @psalm-param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     * @throws MemoryReaderException
     */
    public function readExecutorGlobals(int $pid, string $php_version, int $executor_globals_address): CData
    {
        $eg_raw = $this->memory_reader->read(
            $pid,
            $executor_globals_address,
            $this->getTypeReader($php_version)->sizeOf('zend_executor_globals')
        );
        return $this->getTypeReader($php_version)->readAs('zend_executor_globals', $eg_raw);
    }

    /**
     * @param int $pid
     * @param string $php_version
     * @param CData $eg
     * @return CData
     * @psalm-param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     * @throws MemoryReaderException
     */
    public function readCurrentExecuteData(int $pid, string $php_version, CData $eg): CData
    {
        /**
         * @var \FFI\CPointer $current_execute_data_addr
         * @var \FFI\PhpInternals\zend_executor_globals $eg
         */
        $current_execute_data_addr = \FFI::cast('long', $eg->current_execute_data);
        $current_execute_data_raw = $this->memory_reader->read(
            $pid,
            $current_execute_data_addr->cdata,
            $this->getTypeReader($php_version)->sizeOf('zend_execute_data')
        );
        return clone $this->getTypeReader($php_version)->readAs('zend_execute_data', $current_execute_data_raw);
    }

    /**
     * @param int $pid
     * @param string $php_version
     * @param CData $current_execute_data
     * @return CData
     * @psalm-param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     * @throws MemoryReaderException
     */
    public function readCurrentFunction(int $pid, string $php_version, CData $current_execute_data): CData
    {
        /**
         * @var \FFI\CPointer $func_pointer
         * @var \FFI\PhpInternals\zend_execute_data $current_execute_data
         */
        $func_pointer = \FFI::cast('long', $current_execute_data->func);
        $current_function_raw = $this->memory_reader->read(
            $pid,
            $func_pointer->cdata,
            $this->getTypeReader($php_version)->sizeOf('zend_function')
        );
        return clone $this->getTypeReader($php_version)->readAs('zend_function', $current_function_raw);
    }

    /**
     * @param int $pid
     * @param string $php_version
     * @param CData $current_function
     * @return string
     * @psalm-param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     * @throws MemoryReaderException
     */
    public function readFunctionName(int $pid, string $php_version, CData $current_function): string
    {
        $function_name = '<main>';
        $class_name = '';
        /**
         * @psalm-var \FFI\PhpInternals\zend_function $current_function
         * @psalm-var \FFI\CPointer $current_function_name_pointer
         */
        $current_function_name_pointer = \FFI::cast('long', $current_function->common->function_name);
        if ($current_function_name_pointer->cdata !== 0) {
            /** @var \FFI\CPointer $current_function_name_pointer */
            $current_function_name_zstring = $this->memory_reader->read(
                $pid,
                $current_function_name_pointer->cdata,
                $this->getTypeReader($php_version)->sizeOf('zend_string') + 256
            );
            /** @var \FFI\PhpInternals\zend_string $string */
            $string = $this->getTypeReader($php_version)->readAs('zend_string', $current_function_name_zstring);
            $function_name = \FFI::string($string->val);
            /** @var \FFI\CPointer $current_function_scope_pointer */
            $current_function_scope_pointer = \FFI::cast('long', $current_function->common->scope);
            if ($current_function_scope_pointer->cdata !== 0) {
                $current_function_class_entry = $this->memory_reader->read(
                    $pid,
                    $current_function_scope_pointer->cdata,
                    $this->getTypeReader($php_version)->sizeOf('zend_class_entry')
                );
                /** @var \FFI\PhpInternals\zend_class_entry $class_entry */
                $class_entry = $this->getTypeReader($php_version)
                    ->readAs('zend_class_entry', $current_function_class_entry);
                $current_class_name_pointer = \FFI::cast('long', $class_entry->name);
                /** @var \FFI\CPointer $current_class_name_pointer */
                $current_class_name_zstring = $this->memory_reader->read(
                    $pid,
                    $current_class_name_pointer->cdata,
                    $this->getTypeReader($php_version)->sizeOf('zend_string') + 256
                );
                /** @var \FFI\PhpInternals\zend_string $class_name_string */
                $class_name_string = $this->getTypeReader($php_version)
                    ->readAs('zend_string', $current_class_name_zstring);
                $class_name = \FFI::string($class_name_string->val) . '::';
            }
        }

        return $class_name . $function_name;
    }

    /**
     * @param int $pid
     * @param string $php_version
     * @param CData $current_function
     * @return string
     * @psalm-param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
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
            /** @var \FFI\PhpInternals\zend_string $filename_zstring */
            $filename_zstring = $this->getTypeReader($php_version)->readAs('zend_string', $filename_zstring_raw);
            $filename = \FFI::string($filename_zstring->val);
        }
        return $filename;
    }

    /**
     * @param int $pid
     * @param string $php_version
     * @param int $executor_globals_address
     * @param int $depth
     * @return string[]
     * @psalm-param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     * @throws MemoryReaderException
     */
    public function readCallTrace(int $pid, string $php_version, int $executor_globals_address, int $depth): array
    {
        /** @var \FFI\PhpInternals\zend_executor_globals $eg */
        $eg = $this->readExecutorGlobals($pid, $php_version, $executor_globals_address);

        /** @var \FFI\PhpInternals\zend_execute_data $current_execute_data */
        $current_execute_data = $this->readCurrentExecuteData($pid, $php_version, $eg);

        $stack = [];
        $stack[] = $current_execute_data;
        for ($i = 0; $i < $depth; $i++) {
            $current_execute_data = $this->readPreviousExecuteData($pid, $php_version, $current_execute_data);
            if (is_null($current_execute_data)) {
                break;
            }
            $stack[] = $current_execute_data;
        }

        $result = [];
        foreach ($stack as $current_execute_data) {
            /** @var \FFI\PhpInternals\zend_function $current_function */
            $current_function = $this->readCurrentFunction($pid, $php_version, $current_execute_data);
            $lineno = -1;
            $file = $this->readFunctionFile($pid, $php_version, $current_function);
            if ($file !== '<internal>') {
                $lineno = $this->readOpline($pid, $current_execute_data);
            }
            $result[] = $this->readFunctionName($pid, $php_version, $current_function) . " {$file}({$lineno})";
        }

        return $result;
    }

    /**
     * @param int $pid
     * @param string $php_version
     * @param CData $execute_data
     * @return CData
     * @psalm-param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     * @throws MemoryReaderException
     */
    public function readPreviousExecuteData(int $pid, string $php_version, CData $execute_data): ?CData
    {
        /**
         * @var \FFI\CPointer $previous_execute_data_addr
         * @var \FFI\PhpInternals\zend_execute_data $execute_data
         */
        $previous_execute_data_addr = \FFI::cast('long', $execute_data->prev_execute_data);
        if ($previous_execute_data_addr->cdata == 0) {
            return null;
        }
        $previous_execute_data_raw = $this->memory_reader->read(
            $pid,
            $previous_execute_data_addr->cdata,
            $this->getTypeReader($php_version)->sizeOf('zend_execute_data')
        );
        return clone $this->getTypeReader($php_version)->readAs('zend_execute_data', $previous_execute_data_raw);
    }

    /**
     * @param int $pid
     * @param CData $current_execute_data
     * @return int
     * @throws MemoryReaderException
     */
    public function readOpline(int $pid, CData $current_execute_data): int
    {
        /**
         * @psalm-var \FFI\CPointer $opline_addr
         * @psalm-var \FFI\PhpInternals\zend_execute_data $current_execute_data
         */
        $opline_addr = \FFI::cast('long', $current_execute_data->opline);
        if ($opline_addr->cdata == 0) {
            return -1;
        }
        $opline_raw = $this->memory_reader->read(
            $pid,
            $opline_addr->cdata + 24,
            4
        );
        return $opline_raw[0]
            + ($opline_raw[1] << 9)
            + ($opline_raw[2] << 16)
            + ($opline_raw[3] << 24);
    }
}
