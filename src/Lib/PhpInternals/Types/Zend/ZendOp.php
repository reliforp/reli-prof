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

namespace PhpProfiler\Lib\PhpInternals\Types\Zend;

use FFI\PhpInternals\zend_op;
use PhpProfiler\Lib\PhpInternals\CastedCData;
use PhpProfiler\Lib\Process\Pointer\Dereferencable;
use PhpProfiler\Lib\Process\Pointer\Pointer;

final class ZendOp implements Dereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $op1;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $op2;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $result;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $op1_type;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $op2_type;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $result_type;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $lineno;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $opcode;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $extended_value;

    /** @param CastedCData<zend_op> $casted_cdata */
    public function __construct(
        private CastedCData $casted_cdata,
    ) {
        unset($this->op1);
        unset($this->op2);
        unset($this->result);
        unset($this->op1_type);
        unset($this->op2_type);
        unset($this->result_type);
        unset($this->lineno);
        unset($this->opcode);
        unset($this->extended_value);
    }


    public function __get(string $field_name)
    {
        return match ($field_name) {
            'op1' => $this->op1 = (int)(\FFI::cast('int', $this->casted_cdata->casted->op1)?->cdata ?? -1),
            'op2' => $this->op2 = (int)(\FFI::cast('int', $this->casted_cdata->casted->op2)?->cdata ?? -1),
            'result' => $this->result = (int)(\FFI::cast('int', $this->casted_cdata->casted->result)?->cdata ?? -1),
            'op1_type' => $this->op1_type = $this->casted_cdata->casted->op1_type,
            'op2_type' => $this->op2_type = $this->casted_cdata->casted->op2_type,
            'result_type' => $this->result_type = $this->casted_cdata->casted->result_type,
            'lineno' => $this->lineno = $this->casted_cdata->casted->lineno,
            'opcode' => $this->opcode = $this->casted_cdata->casted->opcode,
            'extended_value' => $this->extended_value = $this->casted_cdata->casted->extended_value,
        };
    }

    public static function getCTypeName(): string
    {
        return 'zend_op';
    }

    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer
    ): static {
        /** @var CastedCData<zend_op> $casted_cdata */
        return new self($casted_cdata);
    }
}
