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
use Reli\Lib\Process\Pointer\PointedTypeResolver;
use Reli\Lib\Process\Pointer\PointedTypeResolverAware;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * @implements \ArrayAccess<int, Zval>
 * @psalm-suppress MethodSignatureMismatch
 */
final class ZvalArray implements \ArrayAccess, Dereferencable, PointedTypeResolverAware
{
    use InlineCDataCreatorTrait;

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

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'zval[0]';
    }

    /**
     * @param CastedCData<CData> $casted_cdata
     * @param Pointer<Dereferencable> $pointer
     */
    #[\Override]
    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer,
        PointedTypeResolver $pointed_type_resolver,
    ): static {
        /**
         * @var CastedCData<\FFI\PhpInternals\zval_array> $casted_cdata
         * @var Pointer<self> $pointer
         */
        $self = new static(
            $casted_cdata,
            (int)($pointer->size / 16),
            $pointer
        );
        $self->pointed_type_resolver = $pointed_type_resolver;
        return $self;
    }

    /**
     * @return Pointer<self>
     */
    #[\Override]
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }

    #[\Override]
    public function offsetExists(mixed $offset): bool
    {
        return $offset < $this->len;
    }

    #[\Override]
    public function offsetGet(mixed $offset): Zval
    {
        if (!isset($this->zvals_cache[$offset])) {
            $this->zvals_cache[$offset] = $this->getZval($offset);
        }
        return $this->zvals_cache[$offset];
    }

    #[\Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('not implemented');
    }

    #[\Override]
    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('not implemented');
    }

    private function getZval(int $offset): Zval
    {
        $zval_class = $this->pointed_type_resolver->resolve(Zval::class);
        return $zval_class::fromCastedCData(
            new CastedCData(
                $this->casted_cdata->casted[$offset],
                $this->casted_cdata->casted[$offset],
            ),
            new Pointer(
                $zval_class,
                $this->pointer->address + 16 * $offset,
                16,
            ),
        );
    }
}
