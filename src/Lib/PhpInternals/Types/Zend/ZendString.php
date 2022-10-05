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

use FFI\PhpInternals\zend_string;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\PhpInternals\Types\C\RawString;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Pointer;

final class ZendString implements Dereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $h;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $len;
    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<RawString>
     */
    public Pointer $val;

    /** @param CastedCData<zend_string> $casted_cdata */
    public function __construct(
        private CastedCData $casted_cdata,
        private int $offset_to_val,
    ) {
        unset($this->h);
        unset($this->len);
        unset($this->val);
    }

    public function __get(string $field_name)
    {
        return match ($field_name) {
            'h' => $this->h = $this->casted_cdata->casted->h,
            'len' => $this->len = $this->casted_cdata->casted->len,
            'val' => $this->val = Pointer::fromCData(
                RawString::class,
                $this->casted_cdata->casted->val,
            )
        };
    }

    public static function getCTypeName(): string
    {
        return 'zend_string';
    }

    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer
    ): static {
        /** @var CastedCData<zend_string> $casted_cdata */
        /*
        $head_addr = \FFI::cast('long', \FFI::addr($cdata))->cdata;
        $val_addr = \FFI::cast('long', \FFI::addr($cdata->val))->cdata;
        $offset_to_val = $val_addr - $head_addr;

        return new self($cdata, $offset_to_val);
        */
        // an almost safe assumption I think
        return new self($casted_cdata, 24);
    }

    /**
     * @param Pointer<ZendString> $pointer
     * @return Pointer<RawString>
     */
    public function getValuePointer(Pointer $pointer): Pointer
    {
        return new Pointer(
            RawString::class,
            $pointer->address + $this->offset_to_val,
            \min($this->len, 255)
        );
    }
}
