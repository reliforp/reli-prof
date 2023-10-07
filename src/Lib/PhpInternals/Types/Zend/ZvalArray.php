<?php

namespace Reli\Lib\PhpInternals\Types\Zend;

use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Pointer;

class ZvalArray implements Dereferencable, \ArrayAccess
{
    private array $zvals_cache = [];

    public function __construct(
        private CastedCData $casted_cdata,
        private int $len,
    ) {
    }

    public static function getCTypeName(): string
    {
        return 'zval[0]';
    }

    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        return new self($casted_cdata, $pointer->size / 16);
    }

    public function offsetExists(mixed $offset): bool
    {
        return $offset < $this->len;
    }

    public function offsetGet(mixed $offset): Zval
    {
        if (!isset($this->zvals_cache[$offset])) {
            $this->zvals_cache[$offset] = $this->getZval($offset);
        }
        return $this->zvals_cache[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('not implemented');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('not implemented');
    }

    private function getZval(int $offset): Zval
    {
        return new Zval(
            new CastedCData(
                $this->casted_cdata->casted[$offset],
                $this->casted_cdata->casted[$offset],
            )
        );
    }

    public static function createPointer(
        int $address,
        int $len,
    ): Pointer {
        return new Pointer(
            self::class,
            $address,
            16 * $len,
        );
    }

    public static function createPointerFromCData(
        int $address,
        int $len,
    ): Pointer {
        return new Pointer(
            self::class,
            $address,
            16 * $len,
        );
    }
}
