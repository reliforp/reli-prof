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

use PhpProfiler\Lib\PhpInternals\Opcodes\OpcodeFactory;
use PhpProfiler\Lib\PhpInternals\Types\Zend\Opline;
use PhpProfiler\Lib\PhpInternals\Types\Zend\ZendCastedTypeProvider;
use PhpProfiler\Lib\PhpInternals\Types\Zend\ZendExecuteData;
use PhpProfiler\Lib\PhpInternals\Types\Zend\ZendExecutorGlobals;
use PhpProfiler\Lib\PhpInternals\Types\Zend\ZendFunction;
use PhpProfiler\Lib\PhpInternals\Types\Zend\ZendOp;
use PhpProfiler\Lib\PhpInternals\ZendTypeReader;
use PhpProfiler\Lib\PhpInternals\ZendTypeReaderCreator;
use PhpProfiler\Lib\PhpProcessReader\CallFrame;
use PhpProfiler\Lib\PhpProcessReader\CallTrace;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderInterface;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderException;
use PhpProfiler\Lib\Process\Pointer\Dereferencer;
use PhpProfiler\Lib\Process\Pointer\Pointer;
use PhpProfiler\Lib\Process\Pointer\RemoteProcessDereferencer;
use PhpProfiler\Lib\Process\ProcessSpecifier;

final class ExecutorGlobalsReader
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
        return new RemoteProcessDereferencer(
            $this->memory_reader,
            new ProcessSpecifier($pid),
            new ZendCastedTypeProvider(
                $this->getTypeReader($php_version),
            )
        );
    }

    /**
     * @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     */
    private function getExecutorGlobals(
        int $eg_address,
        string $php_version,
        Dereferencer $dereferencer
    ): ZendExecutorGlobals {
        $zend_type_reader = $this->getTypeReader($php_version);
        $eg_pointer = new Pointer(
            ZendExecutorGlobals::class,
            $eg_address,
            $zend_type_reader->sizeOf('zend_executor_globals')
        );
        return $dereferencer->deref($eg_pointer);
    }

    /**
     * @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     * @throws MemoryReaderException
     */
    public function readCurrentFunctionName(int $pid, string $php_version, int $executor_globals_address): string
    {
        $dereferencer = $this->getDereferencer($pid, $php_version);
        $eg = $this->getExecutorGlobals($executor_globals_address, $php_version, $dereferencer);
        if (is_null($eg->current_execute_data)) {
            throw new \Exception('cannot read current execute data');
        }
        /**
         * @var ZendExecuteData $current_execute_data
         * @psalm-ignore-var
         */
        $current_execute_data = $dereferencer->deref($eg->current_execute_data);
        return $current_execute_data->getFunctionName($dereferencer) ?? 'unknown';
    }

    /**
     * @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     * @throws MemoryReaderException
     */
    public function readCallTrace(int $pid, string $php_version, int $executor_globals_address, int $depth): ?CallTrace
    {
        $dereferencer = $this->getDereferencer($pid, $php_version);
        $eg = $this->getExecutorGlobals($executor_globals_address, $php_version, $dereferencer);
        if (is_null($eg->current_execute_data)) {
            return null;
        }
        /**
         * @var ZendExecuteData $current_execute_data
         * @psalm-ignore-var
         */
        $current_execute_data = $dereferencer->deref($eg->current_execute_data);

        $stack = [];
        $stack[] = $current_execute_data;
        for ($i = 0; $i < $depth; $i++) {
            if (is_null($current_execute_data->prev_execute_data)) {
                break;
            }
            $current_execute_data = $dereferencer->deref($current_execute_data->prev_execute_data);
            $stack[] = $current_execute_data;
        }

        $result = [];
        foreach ($stack as $current_execute_data) {
            if (is_null($current_execute_data->func)) {
                $result[] = new CallFrame(
                    '',
                    '<unknown>',
                    '<unknown>',
                    null
                );
                continue;
            }
            /**
             * @var ZendFunction $current_function
             * @psalm-ignore-var
             */
            $current_function = $dereferencer->deref($current_execute_data->func);

            $function_name = $current_function->getFunctionName($dereferencer) ?? '<main>';
            $class_name = $current_function->getClassName($dereferencer) ?? '';
            $file_name = $current_function->getFileName($dereferencer) ?? '<unknown>';

            $opline = null;
            if ($file_name !== '<internal>' and !is_null($current_execute_data->opline)) {
                $opline = $this->readOpline(
                    $php_version,
                    $dereferencer->deref($current_execute_data->opline)
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
     * @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     */
    private function readOpline(string $php_version, ZendOp $zend_op): Opline
    {
        return new Opline(
            $zend_op->op1,
            $zend_op->op2,
            $zend_op->result,
            $zend_op->extended_value,
            $zend_op->lineno,
            $this->opcode_factory->create($php_version, $zend_op->opcode),
            $zend_op->op1_type,
            $zend_op->op2_type,
            $zend_op->result_type
        );
    }
}
