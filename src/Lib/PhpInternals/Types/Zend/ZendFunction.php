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

use FFI\PhpInternals\zend_function;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\Pointer\CDataDereferencable;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\FieldReader;
use Reli\Lib\Process\Pointer\LazyDereferencable;
use Reli\Lib\Process\Pointer\PointedTypeResolver;
use Reli\Lib\Process\Pointer\Pointer;

/** @psalm-consistent-constructor */
final class ZendFunction implements LazyDereferencable, CDataDereferencable
{
    public const ZEND_INTERNAL_FUNCTION = 1;
    public const ZEND_USER_FUNCTION = 2;

    private const F_TYPE = 1;
    private const F_FUNC_NAME = 2;
    private const F_SCOPE = 4;
    private const F_NUM_ARGS = 8;
    private const F_FN_FLAGS = 16;
    private const F_FILENAME = 32;
    private const F_OP_ARRAY = 64;

    private int $resolved = 0;

    private int $_type = 0;
    private ?Pointer $_function_name = null;
    private ?Pointer $_scope = null;
    private int $_num_args = 0;
    private int $_fn_flags = 0;
    private ?Pointer $_op_array_filename = null;
    private ?ZendOpArray $_op_array = null;

    private ?FieldReader $field_reader = null;

    /**
     * @param CastedCData<zend_function>|null $casted_cdata
     * @param Pointer<ZendFunction> $pointer
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
        return $self;
    }

    public int $type {
        get {
            if (!($this->resolved & self::F_TYPE)) {
                $this->_type = $this->field_reader !== null
                    ? $this->field_reader->readIntField($this->pointer, 'type')
                    : $this->casted_cdata->casted->type;
                $this->resolved |= self::F_TYPE;
            }
            return $this->_type;
        }
    }

    /** @var Pointer<ZendString>|null */
    public ?Pointer $function_name {
        get {
            if (!($this->resolved & self::F_FUNC_NAME)) {
                $this->_function_name = $this->field_reader !== null
                    ? $this->field_reader->readPointerField(
                        $this->pointer, 'function_name', ZendString::class, 'zend_op_array',
                    )
                    : ($this->casted_cdata->casted->common->function_name !== null
                        ? Pointer::fromCData(ZendString::class, $this->casted_cdata->casted->common->function_name)
                        : null);
                $this->resolved |= self::F_FUNC_NAME;
            }
            return $this->_function_name;
        }
    }

    /** @var Pointer<ZendClassEntry>|null */
    public ?Pointer $scope {
        get {
            if (!($this->resolved & self::F_SCOPE)) {
                $this->_scope = $this->field_reader !== null
                    ? $this->field_reader->readPointerField(
                        $this->pointer, 'scope', ZendClassEntry::class, 'zend_op_array',
                    )
                    : ($this->casted_cdata->casted->common->scope !== null
                        ? Pointer::fromCData(ZendClassEntry::class, $this->casted_cdata->casted->common->scope)
                        : null);
                $this->resolved |= self::F_SCOPE;
            }
            return $this->_scope;
        }
    }

    public int $num_args {
        get {
            if (!($this->resolved & self::F_NUM_ARGS)) {
                $this->_num_args = $this->field_reader !== null
                    ? $this->field_reader->readIntField($this->pointer, 'num_args', 'zend_op_array')
                    : $this->casted_cdata->casted->common->num_args;
                $this->resolved |= self::F_NUM_ARGS;
            }
            return $this->_num_args;
        }
    }

    public int $fn_flags {
        get {
            if (!($this->resolved & self::F_FN_FLAGS)) {
                $this->_fn_flags = $this->field_reader !== null
                    ? $this->field_reader->readIntField($this->pointer, 'fn_flags', 'zend_op_array')
                    : $this->casted_cdata->casted->op_array->fn_flags;
                $this->resolved |= self::F_FN_FLAGS;
            }
            return $this->_fn_flags;
        }
    }

    /** @var Pointer<ZendString>|null */
    public ?Pointer $op_array_filename {
        get {
            if (!($this->resolved & self::F_FILENAME)) {
                $this->_op_array_filename = $this->field_reader !== null
                    ? $this->field_reader->readPointerField(
                        $this->pointer, 'filename', ZendString::class, 'zend_op_array',
                    )
                    : ($this->casted_cdata->casted->op_array->filename !== null
                        ? Pointer::fromCData(ZendString::class, $this->casted_cdata->casted->op_array->filename)
                        : null);
                $this->resolved |= self::F_FILENAME;
            }
            return $this->_op_array_filename;
        }
    }

    public ZendOpArray $op_array {
        get {
            if (!($this->resolved & self::F_OP_ARRAY)) {
                $this->_op_array = $this->field_reader !== null
                    ? new ZendOpArray(
                        $this->field_reader->readEmbeddedStructCData(
                            $this->pointer, 'op_array', 'zend_op_array',
                        )->casted,
                    )
                    : new ZendOpArray($this->casted_cdata->casted->op_array);
                $this->resolved |= self::F_OP_ARRAY;
            }
            /** @var ZendOpArray */
            return $this->_op_array;
        }
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'zend_function';
    }

    #[\Override]
    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer
    ): static {
        /**
         * @var CastedCData<zend_function>|null $casted_cdata
         * @var Pointer<ZendFunction> $pointer
         */
        return new static($casted_cdata, $pointer);
    }

    /** @return Pointer<ZendFunction> */
    #[\Override]
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }

    public function getFullyQualifiedFunctionName(
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
    ): string {
        if (
            $this->isUserFunction()
            and $this->isClosure($zend_type_reader)
        ) {
            return $this->op_array->getDisplayNameForClosure($dereferencer);
        }
        $class_name = $this->getClassName($dereferencer);
        $function_name = $this->getFunctionName(
            $dereferencer,
            $zend_type_reader,
        ) ?? '';
        if (!is_null($class_name)) {
            return $class_name . '::' . $function_name;
        }
        return $function_name;
    }

    private ?string $resolved_name_cache = null;

    public function isClosure(ZendTypeReader $zend_type_reader): bool
    {
        return (bool)($this->fn_flags & (int)$zend_type_reader->constants::ZEND_ACC_CLOSURE);
    }

    public function getFunctionName(
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
    ): ?string {
        if ($this->function_name === null) {
            return null;
        }
        if (!isset($this->resolved_name_cache)) {
            if (
                $this->isUserFunction()
                and $this->isClosure($zend_type_reader)
            ) {
                $this->resolved_name_cache = $this->op_array->getDisplayNameForClosure($dereferencer);
            } else {
                $string = $dereferencer->deref($this->function_name);
                $this->resolved_name_cache = $string->toString($dereferencer);
            }
        }
        return $this->resolved_name_cache;
    }

    private ?string $resolved_class_name_cache = null;

    public function getClassName(Dereferencer $dereferencer): ?string
    {
        if ($this->scope === null) {
            return null;
        }
        if (!isset($this->resolved_class_name_cache)) {
            $class_entry = $dereferencer->deref($this->scope);
            $this->resolved_class_name_cache = $class_entry->getClassName($dereferencer);
        }
        return $this->resolved_class_name_cache;
    }

    private ?string $resolved_file_name_cache = null;

    public function getFileName(Dereferencer $dereferencer): ?string
    {
        if (!isset($this->resolved_file_name_cache)) {
            if ($this->isInternalFunction()) {
                $this->resolved_file_name_cache = '<internal>';
            } elseif ($this->op_array_filename === null) {
                $this->resolved_file_name_cache = null;
            } else {
                $string = $dereferencer->deref($this->op_array_filename);
                $this->resolved_file_name_cache = $string->toString($dereferencer);
            }
        }
        return $this->resolved_file_name_cache;
    }

    public function isUserFunction(): bool
    {
        return $this->type === self::ZEND_USER_FUNCTION;
    }

    public function isInternalFunction(): bool
    {
        return $this->type === self::ZEND_INTERNAL_FUNCTION;
    }
}
