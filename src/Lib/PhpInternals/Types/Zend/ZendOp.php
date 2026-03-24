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

use FFI\PhpInternals\zend_op;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\FieldReader;
use Reli\Lib\Process\Pointer\LazyDereferencable;
use Reli\Lib\Process\Pointer\PointedTypeResolver;
use Reli\Lib\Process\Pointer\Pointer;

final class ZendOp implements LazyDereferencable
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

    private ?FieldReader $field_reader = null;

    /**
     * @param CastedCData<zend_op>|null $casted_cdata
     * @param Pointer<ZendOp> $pointer
     */
    public function __construct(
        private ?CastedCData $casted_cdata,
        private Pointer $pointer,
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

    #[\Override]
    public static function fromLazy(
        FieldReader $field_reader,
        Pointer $pointer,
        ?PointedTypeResolver $pointed_type_resolver = null,
    ): static {
        $self = new self(null, $pointer);
        $self->field_reader = $field_reader;
        return $self;
    }

    public function __get(string $field_name)
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
            'op1' => $this->op1 = $this->field_reader->readIntField($this->pointer, 'op1'),
            'op2' => $this->op2 = $this->field_reader->readIntField($this->pointer, 'op2'),
            'result' => $this->result = $this->field_reader->readIntField($this->pointer, 'result'),
            'op1_type' => $this->op1_type = $this->field_reader->readIntField($this->pointer, 'op1_type'),
            'op2_type' => $this->op2_type = $this->field_reader->readIntField($this->pointer, 'op2_type'),
            'result_type' => $this->result_type = $this->field_reader->readIntField($this->pointer, 'result_type'),
            'lineno' => $this->lineno = $this->field_reader->readIntField($this->pointer, 'lineno'),
            'opcode' => $this->opcode = $this->field_reader->readIntField($this->pointer, 'opcode'),
            'extended_value' => $this->extended_value = $this->field_reader->readIntField(
                $this->pointer,
                'extended_value',
            ),
        };
    }

    private function getFieldEager(string $field_name): mixed
    {
        assert($this->casted_cdata !== null);
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

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'zend_op';
    }

    #[\Override]
    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer
    ): static {
        /**
         * @var CastedCData<zend_op> $casted_cdata
         * @var Pointer<ZendOp> $pointer
         */
        return new self($casted_cdata, $pointer);
    }

    #[\Override]
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }
}
