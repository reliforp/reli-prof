<?php

namespace Reli\Lib\PhpInternals\Types\Zend;

use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Pointer;

class ZendPropertyInfo implements Dereferencable
{
    public int $offset;
    public int $flags;

    /** @var Pointer<ZendString> */
    public ?Pointer $name;

    public function __construct(
        private CastedCData $casted_cdata,
    ) {
        unset($this->offset);
        unset($this->flags);
        unset($this->name);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'offset' => $this->offset = $this->casted_cdata->casted->offset,
            'flags' => $this->flags = $this->casted_cdata->casted->flags,
            'name' => $this->name = $this->casted_cdata->casted->name !== null
                ? Pointer::fromCData(
                    ZendString::class,
                    $this->casted_cdata->casted->name,
                )
                : null
            ,
        };
    }

    public function isStatic(): bool
    {
        return (bool)($this->flags & (1 <<  4));
    }

    public static function getCTypeName(): string
    {
        return 'zend_property_info';
    }

    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        return new self($casted_cdata);
    }
}