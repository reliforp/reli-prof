<?php

namespace Reli\Lib\PhpInternals\Types\Zend;

use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\Pointer;

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

    public function __construct(
        public CastedCData $casted_cdata,
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
        return new static($casted_cdata);
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
}
