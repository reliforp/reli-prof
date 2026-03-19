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
use Reli\Lib\Process\Pointer\PointedTypeResolver;
use Reli\Lib\Process\Pointer\PointedTypeResolverAware;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * @psalm-consistent-constructor
 */
class ZendReference implements Dereferencable, PointedTypeResolverAware
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZendRefcountedH $gc;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public Zval $val;

    private ?PointedTypeResolver $pointed_type_resolver = null;

    /**
     * @param CastedCData<\FFI\PhpInternals\zend_reference> $casted_cdata
     * @param Pointer<ZendReference> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->gc);
        unset($this->val);
    }

    public function setPointedTypeResolver(PointedTypeResolver $resolver): void
    {
        $this->pointed_type_resolver = $resolver;
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'gc' => $this->gc = new ZendRefcountedH($this->casted_cdata->casted->gc),
            'val' => $this->val = $this->createZval(
                $this->casted_cdata->casted->val,
                'val',
            ),
        };
    }

    /** @param \FFI\PhpInternals\zval $cdata */
    private function createZval(mixed $cdata, string $field_name): Zval
    {
        $zval_class = $this->pointed_type_resolver !== null
            ? $this->pointed_type_resolver->resolve(Zval::class)
            : Zval::class;
        return $zval_class::fromCastedCData(
            new CastedCData($cdata, $cdata),
            new Pointer(
                $zval_class,
                $this->pointer->address
                +
                \FFI::typeof($this->casted_cdata->casted)->getStructFieldOffset($field_name),
                \FFI::sizeof($cdata),
            ),
        );
    }

    public static function getCTypeName(): string
    {
        return 'zend_reference';
    }

    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        /**
         * @var CastedCData<\FFI\PhpInternals\zend_reference> $casted_cdata
         * @var Pointer<self> $pointer
         */
        return new static($casted_cdata, $pointer);
    }

    public function getPointer(): Pointer
    {
        return $this->pointer;
    }
}
