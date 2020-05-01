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

namespace PhpProfiler\ProcessReader\PhpMemoryReader;

use PhpProfiler\Lib\PhpInternals\ZendTypeReader;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderInterface;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderException;

/**
 * Class ExecutorGlobalsReader
 * @package PhpProfiler\ProcessReader\PhpStateReader
 */
final class ExecutorGlobalsReader
{
    private MemoryReaderInterface $memory_reader;
    private ZendTypeReader $zend_type_reader;

    /**
     * ExecutorGlobalsReader constructor.
     * @param MemoryReaderInterface $memory_reader
     * @param ZendTypeReader $zend_type_reader
     */
    public function __construct(MemoryReaderInterface $memory_reader, ZendTypeReader $zend_type_reader)
    {
        $this->memory_reader = $memory_reader;
        $this->zend_type_reader = $zend_type_reader;
    }

    /**
     * @param int $pid
     * @param int $executor_globals_address
     * @return string
     * @throws MemoryReaderException
     */
    public function readCurrentFunctionName(int $pid, int $executor_globals_address): string
    {
        $eg_raw = $this->memory_reader->read(
            $pid,
            $executor_globals_address,
            $this->zend_type_reader->sizeOf('zend_executor_globals')
        );
        /** @var \FFI\PhpInternals\zend_executor_globals $eg */
        $eg = $this->zend_type_reader->readAs('zend_executor_globals', $eg_raw);

        /**
         * @var \FFI\CPointer $current_execute_data_addr
         * @psalm-suppress PropertyTypeCoercion
         */
        $current_execute_data_addr = \FFI::cast('long', $eg->current_execute_data);
        $current_execute_data_raw = $this->memory_reader->read(
            $pid,
            $current_execute_data_addr->cdata,
            $this->zend_type_reader->sizeOf('zend_execute_data')
        );
        /** @var \FFI\PhpInternals\zend_execute_data $current_execute_data */
        $current_execute_data = $this->zend_type_reader->readAs('zend_execute_data', $current_execute_data_raw);

        /**
         * @var \FFI\CPointer $func_pointer
         * @psalm-suppress PropertyTypeCoercion
         */
        $func_pointer = \FFI::cast('long', $current_execute_data->func);
        $current_function_raw = $this->memory_reader->read(
            $pid,
            $func_pointer->cdata,
            $this->zend_type_reader->sizeOf('zend_function')
        );
        $current_function = $this->zend_type_reader->readAs('zend_function', $current_function_raw);

        /**
         * @var \FFI\PhpInternals\zend_function $current_function
         * @psalm-suppress PropertyTypeCoercion
         */
        $current_function_name_pointer = \FFI::cast('long', $current_function->common->function_name);
        /** @var \FFI\CPointer $current_function_name_pointer */
        $current_function_name_zstring = $this->memory_reader->read(
            $pid,
            $current_function_name_pointer->cdata,
            $this->zend_type_reader->sizeOf('zend_string') + 256
        );
        /** @var \FFI\PhpInternals\zend_string $string */
        $string = $this->zend_type_reader->readAs('zend_string', $current_function_name_zstring);

        $class_name = '';
        /** @var \FFI\CPointer $current_function_scope_pointer */
        $current_function_scope_pointer = \FFI::cast('long', $current_function->common->scope);
        if ($current_function_scope_pointer->cdata !== 0) {
            $current_function_class_entry = $this->memory_reader->read(
                $pid,
                $current_function_scope_pointer->cdata,
                $this->zend_type_reader->sizeOf('zend_class_entry')
            );
            /** @var \FFI\PhpInternals\zend_class_entry $class_entry */
            $class_entry = $this->zend_type_reader->readAs('zend_class_entry', $current_function_class_entry);
            $current_class_name_pointer = \FFI::cast('long', $class_entry->name);
            /** @var \FFI\CPointer $current_class_name_pointer */
            $current_class_name_zstring = $this->memory_reader->read(
                $pid,
                $current_class_name_pointer->cdata,
                $this->zend_type_reader->sizeOf('zend_string') + 256
            );
            /** @var \FFI\PhpInternals\zend_string $class_name_string */
            $class_name_string = $this->zend_type_reader->readAs('zend_string', $current_class_name_zstring);
            $class_name = \FFI::string($class_name_string->val)  . '::';
        }

        return $class_name . \FFI::string($string->val);
    }
}
