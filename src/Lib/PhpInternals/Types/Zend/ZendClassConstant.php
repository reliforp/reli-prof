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
use Reli\Lib\Process\Pointer\Pointer;
use Reli\Lib\Process\Pointer\PointedTypeResolver;
use Reli\Lib\Process\Pointer\PointedTypeResolverAware;

/**
 * @psalm-consistent-constructor
 * @psalm-suppress ClassMustBeFinal
 */
class ZendClassConstant implements PointedTypeResolverAware
{
    use InlineCDataCreatorTrait;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public Zval $value;

    /** @var Pointer<ZendString>|null */
    public ?Pointer $doc_comment;

    /** @var Pointer<ZendArray>|null */
    public ?Pointer $attributes;

    /** @var Pointer<ZendClassEntry>|null */
    public ?Pointer $ce;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZendType $type;

    /**
     * @param CastedCData<\FFI\PhpInternals\zend_class_constant> $casted_cdata
     * @param Pointer<ZendClassConstant> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->value);
        unset($this->doc_comment);
        unset($this->attributes);
        unset($this->ce);
        unset($this->type);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'value' => $this->value = $this->createInlineDereferencable('value', Zval::class),
            'doc_comment' => $this->doc_comment = $this->casted_cdata->casted->doc_comment !== null
                ? Pointer::fromCData(
                    ZendString::class,
                    $this->casted_cdata->casted->doc_comment,
                )
                : null
            ,
            'attributes' => $this->attributes = $this->casted_cdata->casted->attributes !== null
                ? Pointer::fromCData(
                    ZendArray::class,
                    $this->casted_cdata->casted->attributes,
                )
                : null
            ,
            'ce' => $this->ce = $this->casted_cdata->casted->ce !== null
                ? Pointer::fromCData(
                    ZendClassEntry::class,
                    $this->casted_cdata->casted->ce,
                )
                : null
            ,
            'type' => $this->type = new ZendType(
                $this->casted_cdata->casted->type,
            ),
        };
    }


    #[\Override]
    public static function getCTypeName(): string
    {
        return 'zend_class_constant';
    }

    #[\Override]
    public static function fromCastedCDataWithResolver(
        CastedCData $casted_cdata,
        Pointer $pointer,
        PointedTypeResolver $pointed_type_resolver,
    ): static {
        /**
         * @var CastedCData<\FFI\PhpInternals\zend_class_constant> $casted_cdata
         * @var Pointer<self> $pointer
         */
        $self = new static($casted_cdata, $pointer);
        $self->pointed_type_resolver = $pointed_type_resolver;
        return $self;
    }

    /** @return Pointer<ZendClassConstant> */
    #[\Override]
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }
}
