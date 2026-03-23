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
use Reli\Lib\Process\Pointer\FieldReader;
use Reli\Lib\Process\Pointer\LazyDereferencable;
use Reli\Lib\Process\Pointer\Pointer;

final class ZendExecutorGlobals implements LazyDereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public Zval $uninitialized_zval;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public Zval $error_zval;

    /** @var Pointer<ZendExecuteData>|null */
    public ?Pointer $current_execute_data;

    /** @var Pointer<ZendArray>|null */
    public ?Pointer $function_table;

    /** @var Pointer<ZendArray>|null */
    public ?Pointer $class_table;

    /** @var Pointer<ZendArray>|null */
    public ?Pointer $zend_constants;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZendArray $symbol_table;

    /** @var Pointer<ZendVmStack>|null  */
    public ?Pointer $vm_stack;

    /** @var Pointer<Zval>|null  */
    public ?Pointer $vm_stack_top;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZendArray $included_files;

    /** @var Pointer<ZendArray>|null */
    public ?Pointer $ini_directives;

    /** @var Pointer<ZendArray>|null */
    public ?Pointer $modified_ini_directives;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZendObjectsStore $objects_store;

    private ?FieldReader $field_reader = null;

    /**
     * @param CastedCData<zend_executor_globals>|null $casted_cdata
     * @param Pointer<ZendExecutorGlobals> $pointer
     */
    public function __construct(
        private ?CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->uninitialized_zval);
        unset($this->error_zval);
        unset($this->current_execute_data);
        unset($this->function_table);
        unset($this->class_table);
        unset($this->zend_constants);
        unset($this->symbol_table);
        unset($this->vm_stack);
        unset($this->vm_stack_top);
        unset($this->objects_store);
        unset($this->included_files);
    }

    /**
     * @param Pointer<ZendExecutorGlobals> $pointer
     */
    public static function fromLazy(FieldReader $field_reader, Pointer $pointer): static
    {
        $self = new self(null, $pointer);
        $self->field_reader = $field_reader;
        return $self;
    }

    public function __get(string $field_name): mixed
    {
        if ($this->field_reader !== null) {
            return $this->getFieldLazy($field_name);
        }
        return $this->getFieldEager($field_name);
    }

    private function getFieldLazy(string $field_name): mixed
    {
        assert($this->field_reader !== null);
        return match ($field_name) {
            'current_execute_data' => $this->current_execute_data = $this->field_reader->readPointerField(
                $this->pointer, 'current_execute_data', ZendExecuteData::class,
            ),
            'function_table' => $this->function_table = $this->field_reader->readPointerField(
                $this->pointer, 'function_table', ZendArray::class,
            ),
            'class_table' => $this->class_table = $this->field_reader->readPointerField(
                $this->pointer, 'class_table', ZendArray::class,
            ),
            'zend_constants' => $this->zend_constants = $this->field_reader->readPointerField(
                $this->pointer, 'zend_constants', ZendArray::class,
            ),
            'vm_stack' => $this->vm_stack = $this->field_reader->readPointerField(
                $this->pointer, 'vm_stack', ZendVmStack::class,
            ),
            'vm_stack_top' => $this->vm_stack_top = $this->field_reader->readPointerField(
                $this->pointer, 'vm_stack_top', Zval::class,
            ),
            'ini_directives' => $this->ini_directives = $this->field_reader->readPointerField(
                $this->pointer, 'ini_directives', ZendArray::class,
            ),
            'modified_ini_directives' => $this->modified_ini_directives = $this->field_reader->readPointerField(
                $this->pointer, 'modified_ini_directives', ZendArray::class,
            ),
            default => throw new \LogicException(
                "Field '{$field_name}' is not available in lazy deref mode for ZendExecutorGlobals"
            ),
        };
    }

    private function getFieldEager(string $field_name): mixed
    {
        assert($this->casted_cdata !== null);
        return match ($field_name) {
            'uninitialized_zval' => $this->uninitialized_zval = new Zval(
                new CastedCData(
                    $this->casted_cdata->casted->uninitialized_zval,
                    $this->casted_cdata->casted->uninitialized_zval
                ),
                new Pointer(
                    Zval::class,
                    $this->pointer->address
                    +
                    \FFI::typeof($this->casted_cdata->casted)->getStructFieldOffset('uninitialized_zval'),
                    \FFI::sizeof($this->casted_cdata->casted->uninitialized_zval),
                ),
            ),
            'error_zval' => $this->error_zval = new Zval(
                new CastedCData(
                    $this->casted_cdata->casted->error_zval,
                    $this->casted_cdata->casted->error_zval
                ),
                new Pointer(
                    Zval::class,
                    $this->pointer->address
                    +
                    \FFI::typeof($this->casted_cdata->casted)->getStructFieldOffset('error_zval'),
                    \FFI::sizeof($this->casted_cdata->casted->error_zval),
                ),
            ),
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
                ),
                new Pointer(
                    ZendArray::class,
                    $this->pointer->address
                    +
                    \FFI::typeof($this->casted_cdata->casted)->getStructFieldOffset('symbol_table'),
                    \FFI::sizeof($this->casted_cdata->casted->symbol_table),
                ),
            ),
            'vm_stack' => $this->vm_stack = $this->casted_cdata->casted->vm_stack !== null
                ? Pointer::fromCData(
                    ZendVmStack::class,
                    $this->casted_cdata->casted->vm_stack,
                )
                : null
            ,
            'vm_stack_top' => $this->vm_stack_top = $this->casted_cdata->casted->vm_stack_top !== null
                ? Pointer::fromCData(
                    Zval::class,
                    $this->casted_cdata->casted->vm_stack_top,
                )
                : null
            ,
            'objects_store' => $this->objects_store = new ZendObjectsStore(
                $this->casted_cdata->casted->objects_store,
            ),
            'included_files' => $this->included_files = new ZendArray(
                new CastedCData(
                    $this->casted_cdata->casted->included_files,
                    $this->casted_cdata->casted->included_files
                ),
                new Pointer(
                    ZendArray::class,
                    $this->pointer->address
                    +
                    \FFI::typeof($this->casted_cdata->casted)->getStructFieldOffset('included_files'),
                    \FFI::sizeof($this->casted_cdata->casted->included_files),
                ),
            ),
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
        /**
         * @var CastedCData<zend_executor_globals>|null $casted_cdata
         * @var Pointer<ZendExecutorGlobals> $pointer
         */
        return new self($casted_cdata, $pointer);
    }

    /** @return Pointer<ZendExecutorGlobals> */
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }
}
