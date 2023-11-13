<?php

namespace Reli\Lib\PhpInternals\Types\C;

use FFI\CArray;
use PhpCast\Cast;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Pointer;

final class PointerArray implements Dereferencable
{
    /** @var array<int, Pointer> */
    private array $pointers_cache = [];

    private int $len;

    /**
     * @param CastedCData<CArray<int>> $casted_cdata
     * @param Pointer<PointerArray> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        $this->len = (int)($pointer->size / 8);
    }

    public static function getCTypeName(): string
    {
        return 'intptr_t[0]';
    }

    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer
    ): static {
        /**
         * @var CastedCData<CArray<int>> $casted_cdata
         * @var Pointer<PointerArray> $pointer
         */
        return new static($casted_cdata, $pointer);
    }

    /** @return Pointer<PointerArray> */
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }

    public function countElements(): int
    {
        return $this->len;
    }

    public function isInRange(int $offset): bool
    {
        return $offset < $this->len;
    }

    /**
     * @template T of Dereferencable
     * @param class-string<T> $class_name
     * @return iterable<int, Pointer<T>>
     */
    public function getIteratorOfPointersTo(
        string $class_name,
        ZendTypeReader $zend_type_reader,
    ): iterable {
        for ($i = 0; $i < $this->len; $i++) {
            yield $i => $this->getAsPointerTo($class_name, $i, $zend_type_reader);
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
        ?int $size = null,
    ): Pointer {
        if (!isset($this->pointers_cache[$offset])) {
            $this->pointers_cache[$offset] = $this->getPointerInternal(
                $class_name,
                $offset,
                $zend_type_reader,
                $size,
            );
        }
        assert($this->pointers_cache[$offset]->type === $class_name);
        /** @var Pointer<T> */
        return $this->pointers_cache[$offset];
    }

    /**
     * @template T of Dereferencable
     * @param class-string<T> $class_name
     * @return Pointer<T>
     */
    private function getPointerInternal(
        string $class_name,
        int $offset,
        ZendTypeReader $zend_type_reader,
        ?int $size = null,
    ): Pointer {
        assert(isset($this->casted_cdata->casted[$offset]));
        return new Pointer(
            $class_name,
            Cast::toInt($this->casted_cdata->casted[$offset]),
            $size ?? $zend_type_reader->sizeOf($class_name::getCTypeName()),
        );
    }

    /** @return Pointer<self> */
    public static function createPointerToArray(
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
