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
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * @psalm-consistent-constructor
 */
class ZendVmStack implements Dereferencable
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

    public static function getCTypeName(): string
    {
        return 'struct _zend_vm_stack';
    }

    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        /**
         * @var CastedCData<\FFI\PhpInternals\zend_vm_stack> $casted_cdata
         * @var Pointer<ZendVmStack> $pointer
         */
        return new static($casted_cdata, $pointer);
    }

    /** @return Pointer<ZendVmStack> */
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

    public function materializeAsPointerArray(
        Dereferencer $dereferencer,
        int $end_address,
    ): PointerArray {
        assert($this->top !== null);
        $pointer = new Pointer(
            PointerArray::class,
            $this->top->address,
            $end_address - $this->top->address,
        );
        return $dereferencer->deref($pointer);
    }
}
