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

namespace Reli\Lib\PhpInternals\Types\Zend;

use FFI\PhpInternals\zend_executor_globals;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Pointer;

final class ZendExecutorGlobals implements Dereferencable
{
    /** @var Pointer<ZendExecuteData>|null */
    public ?Pointer $current_execute_data;

    /** @var Pointer<ZendArray>|null */
    public ?Pointer $function_table;

    /** @var Pointer<ZendArray>|null */
    public ?Pointer $class_table;

    /** @var Pointer<ZendArray>|null */
    public ?Pointer $zend_constants;

    public ZendArray $symbol_table;

    /** @var Pointer<ZendVmStack>|null  */
    public ?Pointer $vm_stack;

    /** @param CastedCData<zend_executor_globals> $casted_cdata */
    public function __construct(
        private CastedCData $casted_cdata,
    ) {
        unset($this->current_execute_data);
        unset($this->function_table);
        unset($this->class_table);
        unset($this->zend_constants);
        unset($this->symbol_table);
        unset($this->vm_stack);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'current_execute_data' => $this->casted_cdata->casted->current_execute_data !== null
                ? Pointer::fromCData(
                    ZendExecuteData::class,
                    $this->casted_cdata->casted->current_execute_data,
                )
                : null
            ,
            'function_table' => $this->casted_cdata->casted->function_table !== null
                ? Pointer::fromCData(
                    ZendArray::class,
                    $this->casted_cdata->casted->function_table,
                )
                : null
            ,
            'class_table' => $this->casted_cdata->casted->class_table !== null
                ? Pointer::fromCData(
                    ZendArray::class,
                    $this->casted_cdata->casted->class_table,
                )
                : null
            ,
            'zend_constants' => $this->casted_cdata->casted->zend_constants !== null
                ? Pointer::fromCData(
                    ZendArray::class,
                    $this->casted_cdata->casted->zend_constants,
                )
                : null
            ,
            'symbol_table' => $this->symbol_table = new ZendArray(
                new CastedCData(
                    $this->casted_cdata->casted->symbol_table,
                    $this->casted_cdata->casted->symbol_table
                )
            ),
            'vm_stack' => $this->casted_cdata->casted->vm_stack !== null
                ? Pointer::fromCData(
                    ZendVmStack::class,
                    $this->casted_cdata->casted->vm_stack,
                )
                : null
            ,
        };
    }

    public static function getCTypeName(): string
    {
        return 'zend_executor_globals';
    }

    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer
    ): static {
        /** @var CastedCData<zend_executor_globals> $casted_cdata */
        return new self($casted_cdata);
    }
}
