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
use Reli\Lib\Process\Pointer\CDataDereferencable;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Represents the C stack allocated for a fiber.
 *
 * The actual struct definition is not exposed in PHP's public headers
 * (only a forward declaration exists). The layout is taken from
 * zend_fibers.c: { void *pointer; size_t size; }.
 *
 * Note: the `pointer` field is declared as `uintptr_t` (not `void*`) in
 * our header copy. PHP FFI will SEGV when casting a `void*` CData value
 * that holds a remote process address to `long` — it internally tries to
 * dereference the pointer. Using `uintptr_t` avoids this.
 *
 * @psalm-consistent-constructor
 */
final class ZendFiberStack implements CDataDereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $pointer;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $size;

    /**
     * @param CastedCData<\FFI\PhpInternals\zend_fiber_stack> $casted_cdata
     * @param Pointer<ZendFiberStack> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        /** @var Pointer<ZendFiberStack> */
        private Pointer $pointer_field,
    ) {
        unset($this->pointer);
        unset($this->size);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'pointer' => $this->pointer = $this->casted_cdata->casted->pointer,
            'size' => $this->size = $this->casted_cdata->casted->size,
        };
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'zend_fiber_stack';
    }

    #[\Override]
    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        /**
         * @var CastedCData<\FFI\PhpInternals\zend_fiber_stack> $casted_cdata
         * @var Pointer<ZendFiberStack> $pointer
         */
        return new static($casted_cdata, $pointer);
    }

    /** @return Pointer<ZendFiberStack> */
    #[\Override]
    public function getPointer(): Pointer
    {
        return $this->pointer_field;
    }
}
