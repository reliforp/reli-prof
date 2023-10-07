<?php

namespace Reli\Lib\PhpInternals\Types\Zend;

use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Pointer;

class ZendClassConstant implements Dereferencable
{
    public Zval $value;

    /** @var Pointer<ZendString>|null */
    public ?Pointer $doc_comment;

    /** @var Pointer<ZendArray>|null */
    public ?Pointer $attributes;

    /** @var Pointer<ZendClassEntry>|null */
    public ?Pointer $ce;

    public ZendType $type;

    /** @param CastedCData<zend_class_constant> $casted_cdata */
    public function __construct(
        private CastedCData $cdata,
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
                    $this->cdata->casted->value,
                    $this->cdata->casted->value,
                ),
            ),
            'doc_comment' => $this->doc_comment = $this->cdata->casted->doc_comment !== null
                ? Pointer::fromCData(
                    ZendString::class,
                    $this->cdata->casted->doc_comment,
                )
                : null
            ,
            'attributes' => $this->attributes = $this->cdata->casted->attributes !== null
                ? Pointer::fromCData(
                    ZendArray::class,
                    $this->cdata->casted->attributes,
                )
                : null
            ,
            'ce' => $this->ce = $this->cdata->casted->ce !== null
                ? Pointer::fromCData(
                    ZendClassEntry::class,
                    $this->cdata->casted->ce,
                )
                : null
            ,
            'type' => $this->type = new ZendType(
                $this->cdata->casted->type,
            ),
        };
    }


    public static function getCTypeName(): string
    {
        return 'zend_class_constant';
    }

    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        return new self($casted_cdata);
    }
}
