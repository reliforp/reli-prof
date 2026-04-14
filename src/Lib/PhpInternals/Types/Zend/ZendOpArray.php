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

use FFI;
use FFI\CData;
use FFI\PhpInternals\zend_op_array;
use PhpCast\Cast;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\PhpInternals\Types\C\PointerArray;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\Pointer;

final class ZendOpArray
{
    /**
     * @var Pointer<ZendString>|null
     * @psalm-suppress PropertyNotSetInConstructor
     */
    public ?Pointer $filename;

    /**
     * @var Pointer<ZendArgInfo>|null
     * @psalm-suppress PropertyNotSetInConstructor
     */
    public ?Pointer $arg_info;

    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZendString>|null
     */
    public ?Pointer $doc_comment;

    /**
     * @var Pointer<ZendArray>|null
     * @psalm-suppress PropertyNotSetInConstructor
     */
    public ?Pointer $static_variables;

    /**
     * Runtime pointer to static variables (PHP 7.4+).
     * static_variables is the template; this points to the live copy.
     * @psalm-suppress PropertyNotSetInConstructor
     */
    public int $static_variables_ptr;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $fn_flags;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $last;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $T;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $num_args;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $last_var;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $last_literal;
    /**
     * @var Pointer<ZendArray>|null
     * @psalm-suppress PropertyNotSetInConstructor
     */
    public ?Pointer $literals;

    /** @var Pointer<PointerArray>|null */
    public ?Pointer $vars;

    /** @var Pointer<ZendOp>|null */
    public ?Pointer $opcodes;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $last_live_range;
    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZendLiveRange>|null
     */
    public ?Pointer $live_range;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $num_dynamic_func_defs;

    /** @var Pointer<PointerArray>|null */
    public ?Pointer $dynamic_func_defs;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $cache_size;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $run_time_cache__ptr;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $line_start;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $line_end;

    /** @param CastedCData<zend_op_array> $cdata */
    public function __construct(
        private CastedCData $cdata,
    ) {
        unset($this->fn_flags);
        unset($this->filename);
        unset($this->arg_info);
        unset($this->static_variables);
        unset($this->static_variables_ptr);
        unset($this->last);
        unset($this->T);
        unset($this->num_args);
        unset($this->last_var);
        unset($this->vars);
        unset($this->opcodes);
        unset($this->last_live_range);
        unset($this->live_range);
        unset($this->doc_comment);
        unset($this->last_literal);
        unset($this->literals);
        unset($this->num_dynamic_func_defs);
        unset($this->dynamic_func_defs);
        unset($this->cache_size);
        unset($this->run_time_cache__ptr);
        unset($this->line_start);
        unset($this->line_end);
    }

    public function __get(string $field_name)
    {
        return match ($field_name) {
            'fn_flags' => $this->fn_flags = $this->cdata->casted->fn_flags,
            'filename' => $this->filename = $this->cdata->casted->filename !== null
                ? Pointer::fromCData(
                    ZendString::class,
                    $this->cdata->casted->filename,
                )
                : null
            ,
            'arg_info' => $this->arg_info = $this->cdata->casted->arg_info !== null
                ? Pointer::fromCData(
                    ZendArgInfo::class,
                    $this->cdata->casted->arg_info,
                )
                : null
            ,
            'doc_comment' => $this->doc_comment
                = $this->cdata->casted->doc_comment !== null
                ? Pointer::fromCData(
                    ZendString::class,
                    $this->cdata->casted->doc_comment,
                )
                : null
            ,
            'static_variables' => $this->static_variables = $this->readPointerFieldOrNull(
                'static_variables',
                ZendArray::class,
            ),
            'static_variables_ptr' => $this->static_variables_ptr
                = $this->readStaticVariablesPtr()
            ,
            'last' => $this->last = $this->cdata->casted->last,
            'T' => $this->T = $this->cdata->casted->T,
            'num_args' => $this->num_args = $this->cdata->casted->num_args,
            'last_var' => $this->last_var = $this->cdata->casted->last_var,
            'vars' => $this->vars = $this->cdata->casted->vars !== null
                ? PointerArray::createPointerToArray(
                    $this->readNullablePointerAddress('vars'),
                    $this->cdata->casted->last_var,
                )
                : null

            ,
            'opcodes' => $this->opcodes = $this->cdata->casted->opcodes !== null
                ? Pointer::fromCData(
                    ZendOp::class,
                    $this->cdata->casted->opcodes,
                )
                : null
            ,
            'last_live_range' => $this->last_live_range = $this->getLastLiveRange(),
            'live_range' => $this->live_range = $this->getLiveRange(),
            'last_literal' => $this->last_literal = $this->cdata->casted->last_literal,
            'literals' => $this->literals = $this->cdata->casted->literals !== null
                ? Pointer::fromCData(
                    ZendArray::class,
                    $this->cdata->casted->literals,
                )
                : null
            ,
            'num_dynamic_func_defs' => $this->num_dynamic_func_defs = $this->getNumDynamicFuncDefs(),
            'dynamic_func_defs' => $this->dynamic_func_defs = $this->cdata->casted->dynamic_func_defs !== null
                ? PointerArray::createPointerToArray(
                    $this->readNullablePointerAddress('dynamic_func_defs'),
                    $this->cdata->casted->num_dynamic_func_defs,
                )
                : null
            ,
            'cache_size' => $this->cache_size = $this->cdata->casted->cache_size,
            'run_time_cache' => $this->getRuntimeCacheAddress(),
            'line_start' => $this->line_start = $this->cdata->casted->line_start,
            'line_end' => $this->line_end = $this->cdata->casted->line_end,
        };
    }

    /** @return Pointer<ZendLiveRange>|null */
    private function getLiveRange(): ?Pointer
    {
        if (in_array('live_range', \FFI::typeof($this->cdata->casted)->getStructFieldNames(), true)) {
            return $this->cdata->casted->live_range !== null
                ? Pointer::fromCData(
                    ZendLiveRange::class,
                    $this->cdata->casted->live_range,
                )
                : null
            ;
        }
        return null;
    }

    private function getLastLiveRange(): int
    {
        if (in_array('last_live_range', \FFI::typeof($this->cdata->casted)->getStructFieldNames(), true)) {
            return $this->cdata->casted->last_live_range;
        }
        return 0;
    }

    /** @param Pointer<ZendOp> $opline */
    public function getOpNumFromOpline(Pointer $opline): int
    {
        assert($this->opcodes !== null);
        return Cast::toInt(($opline->address - $this->opcodes->address) / $opline->size);
    }

    public function findLiveTmpVars(
        int $op_num,
        Dereferencer $dereferencer,
    ): array {
        if ($this->live_range === null) {
            return [];
        }
        $result = [];
        for ($i = 0; $i < $this->last_live_range; $i++) {
            $live_range = $dereferencer->deref($this->live_range->indexedAt($i));
            if ($live_range->isInRange($op_num)) {
                $tmp_var_num = $live_range->getTmpVarNum();
                $result[] = $tmp_var_num;
            }
        }
        return $result;
    }

    public function getRuntimeCacheAddress(): int
    {
        $ctype = FFI::typeof($this->cdata->casted);
        if (in_array('run_time_cache__ptr', $ctype->getStructFieldNames(), true)) {
            return $this->readNullablePointerAddress('run_time_cache__ptr');
        } else {
            return $this->readNullablePointerAddress('run_time_cache');
        }
    }

    /** @return iterable<ZendArgInfo> */
    public function iterateArgInfo(
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
    ): iterable {
        if (is_null($this->arg_info)) {
            return [];
        }
        if ($this->hasReturnType($zend_type_reader)) {
            yield $dereferencer->deref($this->arg_info->indexedAt(-1));
        }

        for ($i = 0; $i < $this->num_args; $i++) {
            yield $dereferencer->deref($this->arg_info->indexedAt($i));
        }
    }

    public function isClosure(ZendTypeReader $zend_type_reader): bool
    {
        return (bool)($this->fn_flags & (int)$zend_type_reader->constants::ZEND_ACC_CLOSURE);
    }

    public function getDisplayNameForClosure(
        Dereferencer $dereferencer,
    ): string {
        $file_name = $this->getFileName($dereferencer) ?? '<unknown>';
        return '{closure}(' . $file_name . ':' . $this->line_start . '-' . $this->line_end . ')';
    }

    public function hasReturnType(ZendTypeReader $zend_type_reader): bool
    {
        return (bool)($this->fn_flags & (int)$zend_type_reader->constants::ZEND_ACC_HAS_RETURN_TYPE);
    }

    private function getNumDynamicFuncDefs(): int
    {
        $ctype = FFI::typeof($this->cdata->casted);
        if (in_array('num_dynamic_func_defs', $ctype->getStructFieldNames(), true)) {
            return $this->cdata->casted->num_dynamic_func_defs;
        }
        return 0;
    }

    public function getFileName(Dereferencer $dereferencer): ?string
    {
        if (is_null($this->filename)) {
            return null;
        }
        $filename = $dereferencer->deref($this->filename);
        return $filename->toString($dereferencer);
    }

    /**
     * @psalm-suppress MixedAssignment
     * @psalm-suppress UndefinedPropertyFetch
     * @psalm-suppress MixedArgument
     */
    private function readStaticVariablesPtr(): int
    {
        try {
            $ptr = $this->cdata->casted->static_variables_ptr__ptr;
            if ($ptr === null) {
                return 0;
            }
            return Cast::toInt(
                \Reli\Lib\FFI\FFIHelper::cast('long', $ptr)?->cdata,
            );
        } catch (\Throwable) {
            // Field doesn't exist in PHP < 7.4
            return 0;
        }
    }

    /** @return iterable<int, Pointer<ZendString>> */
    public function getVariableNamesAsIteratorOfPointersToZendStrings(
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
    ): iterable {
        if (is_null($this->vars)) {
            return [];
        }
        $vars = $dereferencer->deref($this->vars);
        return $vars->getIteratorOfPointersTo(
            ZendString::class,
            $zend_type_reader,
        );
    }

    /** @return iterable<int, string> */
    public function getVariableNames(Dereferencer $dereferencer, ZendTypeReader $zend_type_reader): iterable
    {
        $iterator = $this->getVariableNamesAsIteratorOfPointersToZendStrings(
            $dereferencer,
            $zend_type_reader,
        );
        foreach ($iterator as $key => $name_pointer) {
            $zend_string = $dereferencer->deref($name_pointer);
            $string = $zend_string->toString($dereferencer);
            yield $key => $string;
        }
    }

    /** @return iterable<int, Pointer<ZendFunction>> */
    public function iterateDynamicFunctionDefinitions(
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
    ): iterable {
        if (is_null($this->dynamic_func_defs)) {
            return [];
        }
        $dynamic_func_defs = $dereferencer->deref($this->dynamic_func_defs);
        return $dynamic_func_defs->getIteratorOfPointersTo(
            ZendFunction::class,
            $zend_type_reader,
        );
    }

    /**
     * Read a pointer field from the CData, returning null if the field is null
     * or if FFI returns a raw int instead of CData (which can happen for certain
     * PHP versions when reading from remotely-captured memory).
     *
     * @template TType of \Reli\Lib\Process\Pointer\Dereferencable
     * @param class-string<TType> $pointed_type
     * @return Pointer<TType>|null
     * @psalm-suppress ArgumentTypeCoercion
     */
    private function readPointerFieldOrNull(string $field_name, string $pointed_type): ?Pointer
    {
        $value = $this->cdata->casted->$field_name;
        if ($value === null) {
            return null;
        }
        if (!$value instanceof CData) {
            return null;
        }
        return Pointer::fromCData($pointed_type, $value);
    }

    /**
     * @psalm-suppress UndefinedPropertyFetch
     * @psalm-suppress MixedAssignment
     */
    private function readNullablePointerAddress(string $field_name): int
    {
        $value = $this->cdata->casted->$field_name;
        if ($value === null) {
            return 0;
        }
        if (!$value instanceof CData) {
            return 0;
        }
        /** @var \FFI\CPointer $value */
        return \Reli\Lib\FFI\FFIHelper::castPointerToInt($value);
    }
}
