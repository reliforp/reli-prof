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

/** @psalm-consistent-constructor */
class ZendClassConstant implements Dereferencable
{
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
            'value' => $this->value = new Zval(
                new CastedCData(
                    $this->casted_cdata->casted->value,
                    $this->casted_cdata->casted->value,
                ),
                new Pointer(
                    Zval::class,
                    $this->pointer->address
                    +
                    \FFI::typeof($this->casted_cdata->casted)->getStructFieldOffset('value'),
                    \FFI::sizeof($this->casted_cdata->casted->value),
                ),
            ),
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


    public static function getCTypeName(): string
    {
        return 'zend_class_constant';
    }

    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer
    ): static {
        /**
         * @var CastedCData<\FFI\PhpInternals\zend_class_constant> $casted_cdata
         * @var Pointer<self> $pointer
         */
        return new static($casted_cdata, $pointer);
    }

    /** @return Pointer<ZendClassConstant> */
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }
}
