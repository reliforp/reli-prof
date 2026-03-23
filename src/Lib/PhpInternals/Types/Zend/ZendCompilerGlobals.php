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

use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\FieldReader;
use Reli\Lib\Process\Pointer\LazyDereferencable;
use Reli\Lib\Process\Pointer\Pointer;

/** @psalm-consistent-constructor */
class ZendCompilerGlobals implements LazyDereferencable
{
    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZendArena>|null
     */
    public ?Pointer $arena;

    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZendArena>|null
     */
    public ?Pointer $ast_arena;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZendArray $interned_strings;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $map_ptr_base;

    private ?FieldReader $field_reader = null;

    /**
     * @param CastedCData<\FFI\PhpInternals\zend_compiler_globals>|null $casted_cdata
     * @param Pointer<ZendCompilerGlobals> $pointer
     */
    public function __construct(
        public ?CastedCData $casted_cdata,
        public Pointer $pointer,
    ) {
        unset($this->arena);
        unset($this->ast_arena);
        unset($this->map_ptr_base);
        unset($this->interned_strings);
    }

    /**
     * @param Pointer<ZendCompilerGlobals> $pointer
     */
    public static function fromLazy(FieldReader $field_reader, Pointer $pointer): static
    {
        $self = new static(null, $pointer);
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
            'arena' => $this->arena = $this->field_reader->readPointerField(
                $this->pointer, 'arena', ZendArena::class,
            ),
            'ast_arena' => $this->ast_arena = $this->field_reader->readPointerField(
                $this->pointer, 'ast_arena', ZendArena::class,
            ),
            'map_ptr_base' => $this->map_ptr_base = $this->field_reader->readIntField(
                $this->pointer, 'map_ptr_base',
            ),
            default => throw new \LogicException(
                "Field '{$field_name}' is not available in lazy deref mode for ZendCompilerGlobals"
            ),
        };
    }

    private function getFieldEager(string $field_name): mixed
    {
        assert($this->casted_cdata !== null);
        return match ($field_name) {
            'arena' => $this->arena = $this->casted_cdata->casted->arena !== null
                ? Pointer::fromCData(
                    ZendArena::class,
                    $this->casted_cdata->casted->arena,
                )
                : null
            ,
            'ast_arena' => $this->ast_arena = $this->casted_cdata->casted->ast_arena !== null
                ? Pointer::fromCData(
                    ZendArena::class,
                    $this->casted_cdata->casted->ast_arena,
                )
                : null
            ,
            'map_ptr_base' => $this->getMapPtrBase(),
            'interned_strings' => $this->interned_strings = new ZendArray(
                new CastedCData(
                    $this->casted_cdata->casted->interned_strings,
                    $this->casted_cdata->casted->interned_strings,
                ),
                new Pointer(
                    ZendArray::class,
                    $this->pointer->address
                    +
                    \FFI::typeof($this->casted_cdata->casted)
                        ->getStructFieldOffset('interned_strings'),
                    \FFI::sizeof($this->casted_cdata->casted->interned_strings),
                ),
            ),
        };
    }

    public function getMapPtrBase(): int
    {
        if ($this->casted_cdata === null) {
            return $this->field_reader !== null
                ? $this->field_reader->readIntField($this->pointer, 'map_ptr_base')
                : 0;
        }
        $ctype = \FFI::typeof($this->casted_cdata->casted);
        if (in_array('map_ptr_base', $ctype->getStructFieldNames(), true)) {
            return $this->casted_cdata->casted->map_ptr_base;
        } else {
            return 0;
        }
    }

    public static function getCTypeName(): string
    {
        return 'zend_compiler_globals';
    }

    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        /**
         * @var CastedCData<\FFI\PhpInternals\zend_compiler_globals>|null $casted_cdata
         * @var Pointer<ZendCompilerGlobals> $pointer
         */
        return new static($casted_cdata, $pointer);
    }

    /** @return Pointer<ZendCompilerGlobals> */
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }
}
