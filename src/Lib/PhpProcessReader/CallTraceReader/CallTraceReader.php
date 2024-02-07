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
use Reli\Lib\PhpInternals\Types\Zend\Bucket;
use Reli\Lib\PhpInternals\Types\Zend\Opline;
use Reli\Lib\PhpInternals\Types\Zend\ZendArray;
use Reli\Lib\PhpInternals\Types\Zend\ZendCastedTypeProvider;
use Reli\Lib\PhpInternals\Types\Zend\ZendExecutorGlobals;
use Reli\Lib\PhpInternals\Types\Zend\ZendOp;
use Reli\Lib\PhpInternals\Types\Zend\Zval;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpInternals\ZendTypeReaderCreator;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;
use Reli\Lib\Process\MemoryReader\MemoryReaderException;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\PointedTypeResolver;
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
        return new RemoteProcessDereferencer(
            $this->memory_reader,
            new ProcessSpecifier($pid),
            new ZendCastedTypeProvider(
                $this->getTypeReader($php_version),
            ),
            new class ($php_version) implements PointedTypeResolver {
                public function __construct(
                    private string $php_version,
                ) {
                }

                public function resolve(string $type_name): string
                {
                    return match ($this->php_version) {
                        ZendTypeReader::V70,
                        ZendTypeReader::V71,
                        ZendTypeReader::V72 => match ($type_name) {
                            Bucket::class => \Reli\Lib\PhpInternals\Types\Zend\V70\Bucket::class,
                            ZendArray::class => \Reli\Lib\PhpInternals\Types\Zend\V70\ZendArray::class,
                            Zval::class => \Reli\Lib\PhpInternals\Types\Zend\V70\Zval::class,
                            default => $type_name,
                        },
                        ZendTypeReader::V73 => match ($type_name) {
                            Bucket::class => \Reli\Lib\PhpInternals\Types\Zend\V73\Bucket::class,
                            ZendArray::class => \Reli\Lib\PhpInternals\Types\Zend\V73\ZendArray::class,
                            Zval::class => \Reli\Lib\PhpInternals\Types\Zend\V73\Zval::class,
                            default => $type_name,
                        },
                        ZendTypeReader::V74 => match ($type_name) {
                            Bucket::class => \Reli\Lib\PhpInternals\Types\Zend\V74\Bucket::class,
                            ZendArray::class => \Reli\Lib\PhpInternals\Types\Zend\V74\ZendArray::class,
                            Zval::class => \Reli\Lib\PhpInternals\Types\Zend\V74\Zval::class,
                            default => $type_name,
                        },
                        ZendTypeReader::V80,
                        ZendTypeReader::V81 => match ($type_name) {
                            ZendArray::class => \Reli\Lib\PhpInternals\Types\Zend\V80\ZendArray::class,
                            default => $type_name,
                        },
                        ZendTypeReader::V82,
                        ZendTypeReader::V83 => $type_name,
                    };
                }
            }
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
        $dereferencer = $this->getDereferencer($pid, $php_version);
        $eg = $this->getExecutorGlobals($executor_globals_address, $php_version, $dereferencer);
        if (is_null($eg->current_execute_data)) {
            return null;
        }

        $trace_cache->updateCacheKey($this->getGlobalRequestTime($sapi_globals_address, $php_version, $dereferencer));
        $cached_dereferencer = $trace_cache->getDereferencer($dereferencer);

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
