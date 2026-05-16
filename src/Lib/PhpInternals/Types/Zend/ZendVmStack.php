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
use Reli\Lib\PhpInternals\Types\C\PointerArray;
use Reli\Lib\Process\Pointer\CDataDereferencable;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * @psalm-consistent-constructor
 */
final class ZendVmStack implements CDataDereferencable
{
    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<Zval>|null
     */
    public ?Pointer $top;

    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<Zval>|null
     */
    public ?Pointer $end;

    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZendVmStack>|null
     */
    public ?Pointer $prev;

    /**
     * @param CastedCData<\FFI\PhpInternals\zend_vm_stack> $casted_cdata
     * @param Pointer<ZendVmStack> $pointer
     */
    public function __construct(
        public CastedCData $casted_cdata,
        public Pointer $pointer,
    ) {
        unset($this->top);
        unset($this->end);
        unset($this->prev);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'top' => $this->top = $this->casted_cdata->casted->top !== null
                ? Pointer::fromCData(
                    Zval::class,
                    $this->casted_cdata->casted->top,
                )
                : null
            ,
            'end' => $this->end = $this->casted_cdata->casted->end !== null
                ? Pointer::fromCData(
                    Zval::class,
                    $this->casted_cdata->casted->end,
                )
                : null
            ,
            'prev' => $this->prev = $this->casted_cdata->casted->prev !== null
                ? Pointer::fromCData(
                    ZendVmStack::class,
                    $this->casted_cdata->casted->prev,
                )
                : null
            ,
        };
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'struct _zend_vm_stack';
    }

    #[\Override]
    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        /**
         * @var CastedCData<\FFI\PhpInternals\zend_vm_stack> $casted_cdata
         * @var Pointer<ZendVmStack> $pointer
         */
        return new static($casted_cdata, $pointer);
    }

    /** @return Pointer<ZendVmStack> */
    #[\Override]
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }

    public function getSize(): int
    {
        $end = $this->end->address ?? 0;
        $top = $this->top->address ?? 0;
        return $end - $top;
    }

    /** @return iterable<ZendVmStack> */
    public function iterateStackChain(Dereferencer $dereferencer): iterable
    {
        $stack = $this;
        while ($stack !== null) {
            yield $stack;
            if ($stack->prev !== null) {
                $stack = $dereferencer->deref($stack->prev);
            } else {
                $stack = null;
            }
        }
    }

    public function getRootStack(Dereferencer $dereferencer): ZendVmStack
    {
        $stack = $this;
        while ($stack->prev !== null) {
            $stack = $dereferencer->deref($stack->prev);
        }
        return $stack;
    }

    /**
     * Materialize the arbitrary byte range
     * `[$base_address, $top_address)` of this arena's storage as a
     * `PointerArray`. The caller is responsible for picking a range
     * that actually lies inside the arena — this method does no
     * cross-checking against `$this->top` / `$this->end` and only
     * clamps negative sizes to zero.
     */
    public function materializeRangeAsPointerArray(
        Dereferencer $dereferencer,
        int $base_address,
        int $top_address,
    ): PointerArray {
        $size = max(0, $top_address - $base_address);
        $pointer = new Pointer(
            PointerArray::class,
            $base_address,
            $size,
        );
        return $dereferencer->deref($pointer);
    }
}
