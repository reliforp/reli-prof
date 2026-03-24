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
 * @psalm-suppress ClassMustBeFinal
 */
class ZendReference implements Dereferencable, PointedTypeResolverAware
{
    use InlineCDataCreatorTrait;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZendRefcountedH $gc;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public Zval $val;

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

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'gc' => $this->gc = new ZendRefcountedH($this->casted_cdata->casted->gc),
            'val' => $this->val = $this->createInlineDereferencable('val', Zval::class),
        };
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'zend_reference';
    }

    #[\Override]
    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer,
        ?PointedTypeResolver $pointed_type_resolver = null,
    ): static {
        /**
         * @var CastedCData<\FFI\PhpInternals\zend_reference> $casted_cdata
         * @var Pointer<self> $pointer
         */
        $self = new static($casted_cdata, $pointer);
        $self->pointed_type_resolver = $pointed_type_resolver;
        return $self;
    }

    #[\Override]
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }
}
