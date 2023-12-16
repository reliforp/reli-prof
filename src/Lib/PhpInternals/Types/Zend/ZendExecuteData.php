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
use Reli\Lib\Process\Pointer\Pointer;

final class ZendExecuteData implements Dereferencable
{
    /** @var Pointer<ZendFunction>|null */
    public ?Pointer $func;

    /** @var Pointer<ZendExecuteData>|null */
    public ?Pointer $prev_execute_data;

    /** @var Pointer<ZendOp>|null */
    public ?Pointer $opline;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public Zval $This;

    /** @var Pointer<ZendArray>|null  */
    public ?Pointer $symbol_table;

    /** @var Pointer<ZendArray>|null  */
    public ?Pointer $extra_named_params;

    /**
     * @param CastedCData<zend_execute_data> $casted_cdata
     * @param Pointer<ZendExecuteData> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->func);
        unset($this->prev_execute_data);
        unset($this->opline);
        unset($this->This);
        unset($this->symbol_table);
        unset($this->extra_named_params);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'func' => $this->func =
                $this->casted_cdata->casted->func !== null
                ? Pointer::fromCData(
                    ZendFunction::class,
                    $this->casted_cdata->casted->func,
                )
                : null
            ,
            'prev_execute_data' => $this->prev_execute_data =
                $this->casted_cdata->casted->prev_execute_data !== null
                ? Pointer::fromCData(
                    ZendExecuteData::class,
                    $this->casted_cdata->casted->prev_execute_data,
                )
                : null
            ,
            'opline' => $this->opline =
                $this->casted_cdata->casted->opline !== null
                ? Pointer::fromCData(
                    ZendOp::class,
                    $this->casted_cdata->casted->opline
                )
                : null
            ,
            'This' => $this->This = new Zval(
                new CastedCData(
                    $this->casted_cdata->casted->This,
                    $this->casted_cdata->casted->This,
                ),
                new Pointer(
                    Zval::class,
                    $this->pointer->address
                    +
                    \FFI::typeof($this->casted_cdata->casted)->getStructFieldOffset('This'),
                    \FFI::sizeof($this->casted_cdata->casted->This),
                ),
            ),
            'symbol_table' => $this->symbol_table =
                $this->casted_cdata->casted->symbol_table !== null
                ? Pointer::fromCData(
                    ZendArray::class,
                    $this->casted_cdata->casted->symbol_table,
                )
                : null
            ,
            'extra_named_params' => $this->extra_named_params =
                $this->casted_cdata->casted->extra_named_params !== null
                ? Pointer::fromCData(
                    ZendArray::class,
                    $this->casted_cdata->casted->extra_named_params,
                )
                : null
            ,
        };
    }

    public static function getCTypeName(): string
    {
        return 'zend_execute_data';
    }

    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer
    ): static {
        /**
         * @var CastedCData<zend_execute_data> $casted_cdata
         * @var Pointer<ZendExecuteData> $pointer
         */
        return new self($casted_cdata, $pointer);
    }

    /** @return Pointer<ZendExecuteData> */
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
        return $this->pointer->indexedAt(1)->address;
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
