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

    /**
     * Zval type tags that may legitimately appear in a TMP/VAR slot on
     * PHP 7.0 when scanned without live_range guidance. Anything outside
     * this set (IS_UNDEF, internal compiler tags like IS_PTR /
     * IS_CONSTANT_AST, or "UNKNOWN" garbage bytes) is treated as stale.
     * Stored as a flipped map so `isset()` lookup is O(1).
     */
    private const TMP_TYPE_WHITELIST_PHP70 = [
        'IS_NULL' => true,
        'IS_FALSE' => true,
        'IS_TRUE' => true,
        'IS_LONG' => true,
        'IS_DOUBLE' => true,
        'IS_STRING' => true,
        'IS_ARRAY' => true,
        'IS_OBJECT' => true,
        'IS_RESOURCE' => true,
        'IS_REFERENCE' => true,
        'IS_INDIRECT' => true,
    ];

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

    private ?FieldReader $field_reader = null;

    /**
     * @param CastedCData<zend_execute_data>|null $casted_cdata
     * @param Pointer<ZendExecuteData> $pointer
     */
    public function __construct(
        private ?CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->func);
        unset($this->prev_execute_data);
        unset($this->opline);
        unset($this->This);
        unset($this->symbol_table);
        unset($this->extra_named_params);
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
            'func' => $this->func = $this->field_reader->readPointerField(
                $this->pointer,
                'func',
                ZendFunction::class,
            ),
            'prev_execute_data' => $this->prev_execute_data = $this->field_reader->readPointerField(
                $this->pointer,
                'prev_execute_data',
                ZendExecuteData::class,
            ),
            'opline' => $this->opline = $this->field_reader->readPointerField(
                $this->pointer,
                'opline',
                ZendOp::class,
            ),
            'This' => $this->This = $this->field_reader->readEmbeddedDereferencable(
                $this->pointer,
                'This',
                'zval',
                Zval::class,
            ),
            'symbol_table' => $this->symbol_table = $this->field_reader->readPointerField(
                $this->pointer,
                'symbol_table',
                ZendArray::class,
            ),
            'extra_named_params' => $this->extra_named_params = $this->field_reader->readPointerField(
                $this->pointer,
                'extra_named_params',
                ZendArray::class,
            ),
        };
    }

    private function getFieldEager(string $field_name): mixed
    {
        assert($this->casted_cdata !== null);
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
            'This' => $this->This = $this->createInlineDereferencable('This', Zval::class),
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

    public function hasSymbolTable(ZendTypeReader $zend_type_reader): bool
    {
        $flag = (int)$zend_type_reader->constants::ZEND_CALL_HAS_SYMBOL_TABLE;
        if ($flag === 0) {
            // Before 7.1 there was no ZEND_CALL_HAS_SYMBOL_TABLE flag; the
            // engine treated a non-NULL `execute_data->symbol_table` as the
            // indicator (the slot was not yet reused from EG(symtable_cache),
            // which is exactly why the flag was added in 7.1). So on those
            // versions fall back to the pointer itself.
            return $this->symbol_table !== null;
        }
        return (bool)($this->This->u1->type_info & $flag);
    }

    public function hasExtraNamedParams(ZendTypeReader $zend_type_reader): bool
    {
        return (bool)(
            $this->This->u1->type_info
            & (int)$zend_type_reader->constants::ZEND_CALL_HAS_EXTRA_NAMED_PARAMS
        );
    }

    /**
     * Whether this frame is a closure invocation (ZEND_CALL_CLOSURE, set in
     * This.u1.type_info). When set, the closure object is recoverable from
     * the frame's `func` pointer even though no IS_OBJECT zval references it.
     *
     * The bit position is version-dependent (effective bit 29 on 7.0, 21 on
     * 7.1-7.3, 22 on 7.4+), so the value comes from VersionAwareConstants
     * rather than a literal.
     */
    public function isClosureCall(ZendTypeReader $zend_type_reader): bool
    {
        return (bool)(
            $this->This->u1->type_info
            & (int)$zend_type_reader->constants::ZEND_CALL_CLOSURE
        );
    }

    /**
     * Return the closure-object address backing this frame's func, or null if
     * the frame isn't a closure call. ZEND_CALL_CLOSURE is set in
     * `This.u1.type_info` for closure invocations; the closure object pointer
     * can be recovered via the `ZEND_CLOSURE_OBJECT(func)` macro
     * (= `func - offsetof(zend_closure, func)`), which is how PHP itself keeps
     * the closure alive across the call frame (and across Generator suspension
     * — the captured frame retains its `ZEND_CALL_CLOSURE` flag, so the
     * closure's refcount stays bumped without any IS_OBJECT zval reference
     * being held).
     */
    public function getClosureObjectAddress(ZendTypeReader $zend_type_reader): ?int
    {
        if ($this->func === null) {
            return null;
        }
        if (!$this->isClosureCall($zend_type_reader)) {
            return null;
        }
        [$func_offset_in_closure, ] = $zend_type_reader->getOffsetAndSizeOfMember(
            'zend_closure',
            'func',
        );
        $closure_addr = $this->func->address - $func_offset_in_closure;
        if ($closure_addr <= 0) {
            return null;
        }
        return $closure_addr;
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
            try {
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
            } catch (\RuntimeException) {
                $function_name = '<unknown>';
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
        try {
            $func = $dereferencer->deref($this->func);
            return $func->getClassName($dereferencer) ?? '';
        } catch (\RuntimeException) {
            return '';
        }
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
        // PHP always allocates a CV slot for each *declared* parameter
        // even when the caller passed fewer (the missing args are
        // filled in with their default values), so extra args is the
        // overflow PAST the declared count — never negative. The old
        // unclamped `real - arg` undercounted variable_table_size on
        // any frame whose call site omitted optional args, which
        // surfaced as an inter-frame gap in VM stack accounting.
        $extra_arg_num = max(0, $real_arg_num - $arg_num);
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
        $live_tmp_vars_map = $live_tmp_vars === null
            ? null
            : array_flip(array_map($this->liveTmpVarToNum(...), $live_tmp_vars));
        for ($i = $compiled_variables_num; $i < $compiled_variables_num + $tmp_num; $i++) {
            if ($live_tmp_vars_map !== null and !isset($live_tmp_vars_map[$i])) {
                continue;
            }
            $name = '$_T[' . ($i - $compiled_variables_num) . ']';
            $zval = $variable_table->offsetGet($i);
            if ($zval->isUndef()) {
                continue;
            }
            // No liveness info (PHP 7.0): a stale slot can hold a non-UNDEF
            // zval whose type byte is junk, or an internal compiler tag
            // (IS_PTR, IS_CONSTANT_AST, etc.) that has no user-visible
            // representation. Drop those before they reach the resolver,
            // where a bogus value pointer would otherwise turn into a
            // phantom node or an extra cross-edge via address dedup.
            if (
                $live_tmp_vars_map === null
                and !isset(self::TMP_TYPE_WHITELIST_PHP70[$zval->getType()])
            ) {
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
