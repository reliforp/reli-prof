<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */


namespace PhpProfiler\ProcessReader\PhpMemoryReader;

use PhpProfiler\Lib\PhpInternals\ZendTypeReader;
use PhpProfiler\Lib\Process\MemoryReader;
use PhpProfiler\Lib\Process\MemoryReaderException;

/**
 * Class ExecutorGlobalsReader
 * @package PhpProfiler\ProcessReader\PhpStateReader
 */
class ExecutorGlobalsReader
{
    private MemoryReader $memory_reader;
    /**
     * @var ZendTypeReader
     */
    private ZendTypeReader $zend_type_reader;

    /**
     * ExecutorGlobalsReader constructor.
     * @param MemoryReader $memory_reader
     * @param ZendTypeReader $zend_type_reader
     */
    public function __construct(MemoryReader $memory_reader, ZendTypeReader $zend_type_reader)
    {
        $this->memory_reader = $memory_reader;
        $this->zend_type_reader = $zend_type_reader;
    }

    /**
     * @param int $pid
     * @param int $executor_globals_address
     * @throws MemoryReaderException
     */
    public function readCurrentFunctionName(int $pid, int $executor_globals_address): string
    {
        $eg_raw = $this->memory_reader->read($pid, $executor_globals_address, $this->zend_type_reader->sizeOf('zend_executor_globals'));
        $eg = $this->zend_type_reader->readAs('zend_executor_globals', $eg_raw);
        $current_execute_data_addr = \FFI::cast('long', $eg->current_execute_data);
        $current_execute_data_raw = $this->memory_reader->read($pid, $current_execute_data_addr, $this->zend_type_reader->sizeOf('zend_execute_data'));
        $current_execute_data = $this->zend_type_reader->readAs('zend_execute_data', $current_execute_data_raw);
        $func_pointer = \FFI::cast('long',$current_execute_data->func);
        $current_function_raw = $this->memory_reader->read($pid, $func_pointer, $this->zend_type_reader->sizeOf('zend_function'));
        $current_function = $this->zend_type_reader->readAs('zend_function', $current_function_raw);
        $current_function_name_pointer = \FFI::cast('long',$current_function->common->function_name);

        $current_function_name_zstring = $this->memory_reader->read($pid, $current_function_name_pointer, 128);
        $string = $this->zend_type_reader->readAs('zend_string', $current_function_name_zstring);

        return \FFI::string($string->val);
    }
}