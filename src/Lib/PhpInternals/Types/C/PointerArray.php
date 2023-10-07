<?php

namespace Reli\Lib\PhpInternals\Types\C;

use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Pointer;

class PointerArray implements Dereferencable
{
    private array $pointers_cache = [];

    public function __construct(
        private CastedCData $casted_cdata,
        private int $len,
    ) {
    }

    public static function getCTypeName(): string
    {
        return 'intptr_t[0]';
    }

    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        return new self($casted_cdata, $pointer->size / 8);
    }

    public function isExists(int $offset): bool
    {
        return $offset < $this->len;
    }

    /**
     * @template T of Dereferencable
     * @param class-string<T> $class_name
     * @return iterable<Pointer<T>>
     */
    public function getIteratorOfPointersTo(
        string $class_name,
        ZendTypeReader $zend_type_reader,
    ): iterable {
        for ($i = 0; $i < $this->len; $i++) {
            yield $this->getAsPointerTo($class_name, $i, $zend_type_reader);
        }
    }

    /**
     * @template T of Dereferencable
     * @param class-string<T> $class_name
     * @return Pointer<T>
     */
    public function getAsPointerTo(
        string $class_name,
        int $offset,
        ZendTypeReader $zend_type_reader,
    ): Pointer {
        if (!isset($this->pointers_cache[$offset])) {
            $this->pointers_cache[$offset] = $this->getPointer($class_name, $offset, $zend_type_reader);
        }
        return $this->pointers_cache[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('not implemented');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('not implemented');
    }

    /**
     * @template T of Dereferencable
     * @param class-string<T> $class_name
     * @return Pointer<T>
     */
    private function getPointer(
        string $class_name,
        int $offset,
        ZendTypeReader $zend_type_reader,
    ): Pointer {
        return new Pointer(
            $class_name,
            $this->casted_cdata->casted[$offset],
            $zend_type_reader->sizeOf($class_name::getCTypeName()),
        );
    }

    public static function createPointer(
        int $address,
        int $len,
    ): Pointer {
        return new Pointer(
            self::class,
            $address,
            8 * $len,
        );
    }

    public static function createPointerFromCData(
        int $address,
        int $len,
    ): Pointer {
        return new Pointer(
            self::class,
            $address,
            8 * $len,
        );
    }
}
