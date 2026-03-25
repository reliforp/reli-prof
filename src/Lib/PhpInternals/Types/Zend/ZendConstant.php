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
use Reli\Lib\Process\Pointer\Pointer;
use Reli\Lib\Process\Pointer\PointedTypeResolver;
use Reli\Lib\Process\Pointer\PointedTypeResolverAware;

final class ZendConstant implements Dereferencable, PointedTypeResolverAware
{
    use InlineCDataCreatorTrait;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public Zval $value;

    /** @var Pointer<ZendString>|null */
    public ?Pointer $name;

    /**
     * @param CastedCData<\FFI\PhpInternals\zend_constants> $casted_cdata
     * @param Pointer<ZendConstant> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->value);
        unset($this->name);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'value' => $this->value = $this->createInlineDereferencable('value', Zval::class),
            'name' => $this->name = $this->casted_cdata->casted->name !== null
                ? Pointer::fromCData(
                    ZendString::class,
                    $this->casted_cdata->casted->name,
                )
                : null
            ,
        };
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'zend_constant';
    }

    #[\Override]
    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer,
    ): static {
        /**
         * @var CastedCData<\FFI\PhpInternals\zend_constants> $casted_cdata
         * @var Pointer<ZendConstant> $pointer
         */
        return new static($casted_cdata, $pointer);
    }

    #[\Override]
    public static function fromCastedCDataWithResolver(
        CastedCData $casted_cdata,
        Pointer $pointer,
        PointedTypeResolver $pointed_type_resolver,
    ): static {
        $self = static::fromCastedCData($casted_cdata, $pointer);
        $self->pointed_type_resolver = $pointed_type_resolver;
        return $self;
    }

    /** @return Pointer<ZendConstant> */
    #[\Override]
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }
}
