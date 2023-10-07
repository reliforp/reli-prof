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

    public Zval $This;

    /**
     * @param CastedCData<zend_execute_data> $casted_cdata
     * @param Pointer<ZendExecuteData> $original_pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $original_pointer,
    ) {
        unset($this->func);
        unset($this->prev_execute_data);
        unset($this->opline);
        unset($this->This);
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
            ),
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

    public function getFunctionName(Dereferencer $dereferencer): ?string
    {
        if (is_null($this->func)) {
            return null;
        }
        $func = $dereferencer->deref($this->func);
        return $func->getFullyQualifiedFunctionName($dereferencer);
    }

    /** @return iterable<ZendExecuteData> */
    public function iterateStackChain(Dereferencer $dereferencer): iterable
    {
        $stack = $this;
        while ($stack !== null) {
            yield $stack;
            if (is_null($stack->prev_execute_data)) {
                break;
            }
            $stack = $dereferencer->deref($stack->prev_execute_data);
        }
    }

    public function getVariableTableAddress(): int
    {
        return $this->original_pointer->indexedAt(1)->address;
    }

    /** @return iterable<string, Zval> */
    public function getVariables(Dereferencer $dereferencer, ZendTypeReader $zend_type_reader): iterable
    {
        if (is_null($this->func)) {
            return [];
        }
        $func = $dereferencer->deref($this->func);
        if (!$func->isUserFunction()) {
            return [];
        }
        $compiled_variables_num = $func->op_array->last_var;
        $tmp_num = $func->op_array->T;
        $arg_num = $func->op_array->num_args;
        $real_arg_num = $this->This->u2->num_args;
        $extra_arg_num = $real_arg_num - $arg_num;
        $total_variables_num = $compiled_variables_num + $tmp_num + $extra_arg_num;
        $variable_table_address = $this->getVariableTableAddress();
        $variable_table_pointer = new Pointer(
            ZvalArray::class,
            $variable_table_address,
            16 * $total_variables_num,
        );
        $variable_table = $dereferencer->deref($variable_table_pointer);
        foreach ($func->op_array->getVariableNames($dereferencer, $zend_type_reader) as $key => $name) {
            $zval = $variable_table->offsetGet($key);
            if ($zval->isUndef()) {
                continue;
            }
            yield $name => $zval;
        }
        return;
        for ($i = $compiled_variables_num; $i < $compiled_variables_num + $tmp_num; $i++) {
            $name = '$_T' . ($i - $compiled_variables_num);
            $zval = $variable_table->offsetGet($i);
            if ($zval->isUndef()) {
                var_dump('skip ' . $name);
                continue;
            } else {
                var_dump('get ' . $name);
            }
            yield $name => $variable_table->offsetGet($i);
        }
        for ($i = $compiled_variables_num + $tmp_num; $i < $total_variables_num; $i++) {
            $name = '$_ExtraArgs' . ($i - $compiled_variables_num - $tmp_num);
            $zval = $variable_table->offsetGet($i);
            if ($zval->isUndef()) {
                var_dump('skip ' . $name);
                continue;
            } else {
                var_dump('get ' . $name);
            }
            yield $name => $zval;
        }
    }
}
