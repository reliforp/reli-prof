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

use FFI\CData;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Pointer;

/** @implements \ArrayAccess<int, Zval> */
final class ZvalArray implements \ArrayAccess, Dereferencable
{
    /** @var array<int, Zval> */
    private array $zvals_cache = [];

    /**
     * @param CastedCData<\FFI\PhpInternals\zval_array> $casted_cdata
     * @param Pointer<ZvalArray> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private int $len,
        private Pointer $pointer,
    ) {
    }

    public static function getCTypeName(): string
    {
        return 'zval[0]';
    }

    /**
     * @param CastedCData<CData> $casted_cdata
     * @param Pointer<Dereferencable> $pointer
     */
    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer
    ): static {
        /**
         * @var CastedCData<\FFI\PhpInternals\zval_array> $casted_cdata
         * @var Pointer<self> $pointer
         */
        return new static(
            $casted_cdata,
            (int)($pointer->size / 16),
            $pointer
        );
    }

    /**
     * @return Pointer<self>
     */
    public function getPointer(): Pointer
    {
        return $this->pointer;
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
            ),
            new Pointer(
                Zval::class,
                $this->pointer->address + 16 * $offset,
                16,
            ),
        );
    }
}
