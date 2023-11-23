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
use Reli\Lib\PhpInternals\Types\C\PointerArray;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\Pointer;

class ZendOpArray
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

    /** @param zend_op_array $cdata */
    public function __construct(
        private CData $cdata,
    ) {
        unset($this->fn_flags);
        unset($this->filename);
        unset($this->arg_info);
        unset($this->static_variables);
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
            'fn_flags' => $this->fn_flags = $this->cdata->fn_flags,
            'filename' => $this->filename = $this->cdata->filename !== null
                ? Pointer::fromCData(
                    ZendString::class,
                    $this->cdata->filename,
                )
                : null
            ,
            'arg_info' => $this->arg_info = $this->cdata->arg_info !== null
                ? Pointer::fromCData(
                    ZendArgInfo::class,
                    $this->cdata->arg_info,
                )
                : null
            ,
            'doc_comment' => $this->doc_comment
                = $this->cdata->doc_comment !== null
                ? Pointer::fromCData(
                    ZendString::class,
                    $this->cdata->doc_comment,
                )
                : null
            ,
            'static_variables' => $this->static_variables = $this->cdata->static_variables !== null
                ? Pointer::fromCData(
                    ZendArray::class,
                    $this->cdata->static_variables,
                )
                : null
            ,
            'last' => $this->cdata->last,
            'T' => $this->cdata->T,
            'num_args' => $this->cdata->num_args,
            'last_var' => $this->cdata->last_var,
            'vars' => $this->vars = $this->cdata->vars !== null
                ? PointerArray::createPointerToArray(
                    Cast::toInt(FFI::cast('long', $this->cdata->vars)?->cdata),
                    $this->cdata->last_var,
                )
                : null
            ,
            'opcodes' => $this->opcodes = $this->cdata->opcodes !== null
                ? Pointer::fromCData(
                    ZendOp::class,
                    $this->cdata->opcodes,
                )
                : null
            ,
            'last_live_range' => $this->last_live_range = $this->getLastLiveRange(),
            'live_range' => $this->live_range = $this->getLiveRange(),
            'last_literal' => $this->last_literal = $this->cdata->last_literal,
            'literals' => $this->literals = $this->cdata->literals !== null
                ? Pointer::fromCData(
                    ZendArray::class,
                    $this->cdata->literals,
                )
                : null
            ,
            'num_dynamic_func_defs' => $this->num_dynamic_func_defs = $this->getNumDynamicFuncDefs(),
            'dynamic_func_defs' => $this->dynamic_func_defs = $this->cdata->dynamic_func_defs !== null
                ? PointerArray::createPointerToArray(
                    Cast::toInt(
                        FFI::cast('long', $this->cdata->dynamic_func_defs)?->cdata,
                    ),
                    $this->cdata->num_dynamic_func_defs,
                )
                : null
            ,
            'cache_size' => $this->cache_size = $this->cdata->cache_size,
            'run_time_cache' => $this->getRuntimeCacheAddress(),
            'line_start' => $this->line_start = $this->cdata->line_start,
            'line_end' => $this->line_end = $this->cdata->line_end,
        };
    }

    /** @return Pointer<ZendLiveRange>|null */
    private function getLiveRange(): ?Pointer
    {
        if (in_array('live_range', \FFI::typeof($this->cdata)->getStructFieldNames(), true)) {
            return $this->cdata->live_range !== null
                ? Pointer::fromCData(
                    ZendLiveRange::class,
                    $this->cdata->live_range,
                )
                : null
            ;
        }
        return null;
    }

    private function getLastLiveRange(): int
    {
        if (in_array('last_live_range', \FFI::typeof($this->cdata)->getStructFieldNames(), true)) {
            return $this->cdata->last_live_range;
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
        $ctype = FFI::typeof($this->cdata);
        if (in_array('run_time_cache__ptr', $ctype->getStructFieldNames(), true)) {
            return Cast::toInt(FFI::cast('long', $this->cdata->run_time_cache__ptr)?->cdata);
        } else {
            return Cast::toInt(FFI::cast('long', $this->cdata->run_time_cache)?->cdata);
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
        $ctype = FFI::typeof($this->cdata);
        if (in_array('num_dynamic_func_defs', $ctype->getStructFieldNames(), true)) {
            return $this->cdata->num_dynamic_func_defs;
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
}
