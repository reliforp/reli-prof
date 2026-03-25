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

use FFI\PhpInternals\zend_execute_data;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\FieldReader;
use Reli\Lib\Process\Pointer\LazyDereferencable;
use Reli\Lib\Process\Pointer\Pointer;
use Reli\Lib\Process\Pointer\PointedTypeResolver;
use Reli\Lib\Process\Pointer\PointedTypeResolverAware;

final class ZendExecuteData implements LazyDereferencable, PointedTypeResolverAware
{
    use InlineCDataCreatorTrait;

    private const F_FUNC = 1;
    private const F_PREV = 2;
    private const F_OPLINE = 4;
    private const F_THIS = 8;
    private const F_SYMTAB = 16;
    private const F_EXTRA = 32;

    private int $resolved = 0;

    /** @var Pointer<ZendFunction>|null */
    private ?Pointer $_func = null;

    /** @var Pointer<ZendExecuteData>|null */
    private ?Pointer $_prev_execute_data = null;

    /** @var Pointer<ZendOp>|null */
    private ?Pointer $_opline = null;

    private ?Zval $_This = null;

    /** @var Pointer<ZendArray>|null */
    private ?Pointer $_symbol_table = null;

    /** @var Pointer<ZendArray>|null */
    private ?Pointer $_extra_named_params = null;

    private ?FieldReader $field_reader = null;

    /**
     * @param CastedCData<zend_execute_data>|null $casted_cdata
     * @param Pointer<ZendExecuteData> $pointer
     */
    public function __construct(
        private ?CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
    }

    #[\Override]
    public static function fromLazy(
        FieldReader $field_reader,
        Pointer $pointer,
        ?PointedTypeResolver $pointed_type_resolver = null,
    ): static {
        $self = new self(null, $pointer);
        $self->field_reader = $field_reader;
        assert($pointed_type_resolver !== null);
        $self->pointed_type_resolver = $pointed_type_resolver;
        return $self;
    }

    /** @var Pointer<ZendFunction>|null */
    public ?Pointer $func {
        get {
            if (!($this->resolved & self::F_FUNC)) {
                $this->_func = $this->field_reader !== null
                    ? $this->field_reader->readPointerField($this->pointer, 'func', ZendFunction::class)
                    : ($this->casted_cdata->casted->func !== null
                        ? Pointer::fromCData(ZendFunction::class, $this->casted_cdata->casted->func)
                        : null);
                $this->resolved |= self::F_FUNC;
            }
            return $this->_func;
        }
    }

    /** @var Pointer<ZendExecuteData>|null */
    public ?Pointer $prev_execute_data {
        get {
            if (!($this->resolved & self::F_PREV)) {
                $this->_prev_execute_data = $this->field_reader !== null
                    ? $this->field_reader->readPointerField($this->pointer, 'prev_execute_data', ZendExecuteData::class)
                    : ($this->casted_cdata->casted->prev_execute_data !== null
                        ? Pointer::fromCData(ZendExecuteData::class, $this->casted_cdata->casted->prev_execute_data)
                        : null);
                $this->resolved |= self::F_PREV;
            }
            return $this->_prev_execute_data;
        }
    }

    /** @var Pointer<ZendOp>|null */
    public ?Pointer $opline {
        get {
            if (!($this->resolved & self::F_OPLINE)) {
                $this->_opline = $this->field_reader !== null
                    ? $this->field_reader->readPointerField($this->pointer, 'opline', ZendOp::class)
                    : ($this->casted_cdata->casted->opline !== null
                        ? Pointer::fromCData(ZendOp::class, $this->casted_cdata->casted->opline)
                        : null);
                $this->resolved |= self::F_OPLINE;
            }
            return $this->_opline;
        }
    }

    public Zval $This {
        get {
            if (!($this->resolved & self::F_THIS)) {
                /** @var Zval */
                $this->_This = $this->field_reader !== null
                    ? $this->field_reader->readEmbeddedDereferencable($this->pointer, 'This', 'zval', Zval::class)
                    : $this->createInlineDereferencable('This', Zval::class);
                $this->resolved |= self::F_THIS;
            }
            /** @var Zval */
            return $this->_This;
        }
    }

    /** @var Pointer<ZendArray>|null */
    public ?Pointer $symbol_table {
        get {
            if (!($this->resolved & self::F_SYMTAB)) {
                $this->_symbol_table = $this->field_reader !== null
                    ? $this->field_reader->readPointerField($this->pointer, 'symbol_table', ZendArray::class)
                    : ($this->casted_cdata->casted->symbol_table !== null
                        ? Pointer::fromCData(ZendArray::class, $this->casted_cdata->casted->symbol_table)
                        : null);
                $this->resolved |= self::F_SYMTAB;
            }
            return $this->_symbol_table;
        }
    }

    /** @var Pointer<ZendArray>|null */
    public ?Pointer $extra_named_params {
        get {
            if (!($this->resolved & self::F_EXTRA)) {
                $this->_extra_named_params = $this->field_reader !== null
                    ? $this->field_reader->readPointerField($this->pointer, 'extra_named_params', ZendArray::class)
                    : ($this->casted_cdata->casted->extra_named_params !== null
                        ? Pointer::fromCData(ZendArray::class, $this->casted_cdata->casted->extra_named_params)
                        : null);
                $this->resolved |= self::F_EXTRA;
            }
            return $this->_extra_named_params;
        }
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'zend_execute_data';
    }

    #[\Override]
    public static function fromCastedCDataWithResolver(
        CastedCData $casted_cdata,
        Pointer $pointer,
        PointedTypeResolver $pointed_type_resolver,
    ): static {
        /**
         * @var CastedCData<zend_execute_data> $casted_cdata
         * @var Pointer<ZendExecuteData> $pointer
         */
        $self = new self($casted_cdata, $pointer);
        $self->pointed_type_resolver = $pointed_type_resolver;
        return $self;
    }

    /** @return Pointer<ZendExecuteData> */
    #[\Override]
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }

    public function hasThis(): bool
    {
        return $this->This->value->obj !== null
            and ($this->This->u1->type_info & (8 | ((1 << 0) << 8) | ((1 << 1) << 8)))
        ;
    }

    public function isFunctionlessCall(ZendTypeReader $zend_type_reader): bool
    {
        return
            (bool)($this->This->u1->type_info & (int)$zend_type_reader->constants::ZEND_CALL_CODE)
            or
            (bool)($this->This->u1->type_info & (int)$zend_type_reader->constants::ZEND_CALL_TOP)
        ;
    }

    public function hasSymbolTable(): bool
    {
        return (bool)($this->This->u1->type_info & (1 << 20));
    }

    public function hasExtraNamedParams(): bool
    {
        return (bool)($this->This->u1->type_info & (1 << 27));
    }

    public function isInternalCall(Dereferencer $dereferencer): bool
    {
        if (is_null($this->func)) {
            return false;
        }
        $func = $dereferencer->deref($this->func);
        return $func->isInternalFunction();
    }

    public function getFunctionName(
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
    ): string {
        $function_name = null;
        if (is_null($this->func)) {
            if ($this->This->isObject() and !is_null($this->This->value->obj)) {
                $object = $dereferencer->deref($this->This->value->obj);
                if (!is_null($object->ce)) {
                    $class_entry = $dereferencer->deref($object->ce);
                    if ($class_entry->getClassName($dereferencer) === 'Generator') {
                        $function_name = '<generator>';
                    }
                }
            }
        } else {
            $func = $dereferencer->deref($this->func);
            $function_name = $func->getFunctionName($dereferencer, $zend_type_reader);
            $func = $dereferencer->deref($this->func);
            if (is_null($function_name)) {
                if ($this->isFunctionlessCall($zend_type_reader)) {
                    $function_name = '<main>';
                } elseif (!$func->isUserFunction()) {
                    $function_name = '<internal>';
                }
            }
        }
        if ($function_name === '' or is_null($function_name)) {
            $function_name = '<unknown>';
        }
        return $function_name;
    }

    public function getFunctionClassName(
        Dereferencer $dereferencer,
    ): string {
        if (is_null($this->func)) {
            return '';
        }
        $func = $dereferencer->deref($this->func);
        return $func->getClassName($dereferencer) ?? '';
    }

    public function getFullyQualifiedFunctionName(
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
    ): string {
        $function_name = $this->getFunctionName($dereferencer, $zend_type_reader);
        if (
            $function_name === '<internal>'
            or $function_name === '<main>'
            or $function_name === '<generator>'
        ) {
            return $function_name;
        }
        $class_name = $this->getFunctionClassName($dereferencer);
        if ($class_name === '') {
            return $function_name;
        }
        return $class_name . '::' . $function_name;
    }

    /** @return iterable<int, ZendExecuteData> */
    public function iterateStackChain(Dereferencer $dereferencer): iterable
    {
        yield $this;
        $stack = $this;
        while (!is_null($stack->prev_execute_data)) {
            yield $stack = $dereferencer->deref($stack->prev_execute_data);
        }
    }

    public function getRootFrame(
        Dereferencer $dereferencer,
        int $max_depth,
    ): ZendExecuteData {
        $depth = 0;
        $stack = $this;
        while (!is_null($stack->prev_execute_data) and ($depth < $max_depth or $max_depth === -1)) {
            $stack = $dereferencer->deref($stack->prev_execute_data);
            $depth++;
        }
        return $stack;
    }

    public function getVariableTableAddress(): int
    {
        return (int)($this->pointer->address
            + (int)((($this->pointer->size) + 16 - 1) / 16) * 16
        );
    }

    public function getTotalVariablesNum(Dereferencer $dereferencer): int
    {
        if (is_null($this->func)) {
            return 0;
        }
        $func = $dereferencer->deref($this->func);
        if (!$func->isUserFunction()) {
            return $this->This->u2->num_args;
        }
        $compiled_variables_num = $func->op_array->last_var;
        $tmp_num = $func->op_array->T;
        $arg_num = $func->op_array->num_args;
        $real_arg_num = $this->This->u2->num_args;
        $extra_arg_num = $real_arg_num - $arg_num;
        return $compiled_variables_num + $tmp_num + $extra_arg_num;
    }

    /** @return Pointer<ZvalArray> */
    public function getVariableTablePointer(Dereferencer $dereferencer): Pointer
    {
        return new Pointer(
            ZvalArray::class,
            $this->getVariableTableAddress(),
            16 * $this->getTotalVariablesNum($dereferencer),
        );
    }

    /** @return Pointer<ZvalArray> */
    public function getInternalVariableTablePointer(Dereferencer $dereferencer): Pointer
    {
        return new Pointer(
            ZvalArray::class,
            $this->pointer->address + ($this->getCallFrameSlot()) * 16,
            16 * $this->getTotalVariablesNum($dereferencer),
        );
    }

    /** @return iterable<string, Zval> */
    public function getVariablesInternal(
        Dereferencer $dereferencer,
    ): iterable {
        $variable_table_pointer = $this->getInternalVariableTablePointer($dereferencer);
        $variable_table = $dereferencer->deref($variable_table_pointer);
        $passed_count = $this->getTotalVariablesNum($dereferencer);

        for ($i = 0; $i < $passed_count; $i++) {
            if (!isset($variable_table[$i])) {
                continue;
            }
            $zval = $variable_table[$i];
            if ($zval->isUndef()) {
                continue;
            }
            yield '$args_to_internal_function[' . $i . ']' => $zval;
        }
    }

    /** @return iterable<string, Zval> */
    public function getVariables(Dereferencer $dereferencer, ZendTypeReader $zend_type_reader): iterable
    {
        if (is_null($this->func)) {
            return [];
        }
        $func = $dereferencer->deref($this->func);

        $total_variables_num = $this->getTotalVariablesNum($dereferencer);
        if ($total_variables_num === 0) {
            return [];
        }
        if (!$func->isUserFunction()) {
            yield from $this->getVariablesInternal($dereferencer);
            return [];
        }

        $variable_table_pointer = $this->getVariableTablePointer($dereferencer);
        $variable_table = $dereferencer->deref($variable_table_pointer);
        foreach ($func->op_array->getVariableNames($dereferencer, $zend_type_reader) as $key => $name) {
            $zval = $variable_table->offsetGet($key);
            if ($zval->isUndef()) {
                continue;
            }
            yield $name => $zval;
        }

        $func = $dereferencer->deref($this->func);
        $compiled_variables_num = $func->op_array->last_var;
        $tmp_num = $func->op_array->T;
        assert(!is_null($this->opline));
        $current_op_num = $func->op_array->getOpNumFromOpline($this->opline);
        $live_tmp_vars = $func->op_array->findLiveTmpVars($current_op_num, $dereferencer);
        $live_tmp_vars_map = array_flip(array_map($this->liveTmpVarToNum(...), $live_tmp_vars));
        for ($i = $compiled_variables_num; $i < $compiled_variables_num + $tmp_num; $i++) {
            if (!isset($live_tmp_vars_map[$i])) {
                continue;
            }
            $name = '$_T[' . ($i - $compiled_variables_num) . ']';
            $zval = $variable_table->offsetGet($i);
            if ($zval->isUndef()) {
                continue;
            }
            yield $name => $zval;
        }
        for ($i = $compiled_variables_num + $tmp_num; $i < $total_variables_num; $i++) {
            $name = '$_ExtraArgs[' . ($i - $compiled_variables_num - $tmp_num) . ']';
            $zval = $variable_table->offsetGet($i);
            if ($zval->isUndef()) {
                continue;
            }
            yield $name => $zval;
        }
    }

    public function liveTmpVarToNum(int $live_tmp_var): int
    {
        return (int)($live_tmp_var / 16) - $this->getCallFrameSlot();
    }

    public function getCallFrameSlot(): int
    {
        return (int)(($this->pointer->size + 16 - 1) / 16);
    }
}
