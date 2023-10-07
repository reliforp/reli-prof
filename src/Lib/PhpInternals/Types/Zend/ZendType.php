<?php

namespace Reli\Lib\PhpInternals\Types\Zend;

use FFI\CData;

class ZendType
{
    public ?int $ptr;
    public int $type_mask;

    public function __construct(
        private CData $cdata,
    ) {
        unset($this->ptr);
        unset($this->type_mask);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'ptr' => $this->ptr = $this->cdata->ptr,
            'type_mask' => $this->type_mask = $this->cdata->type_mask,
        };
    }
}
