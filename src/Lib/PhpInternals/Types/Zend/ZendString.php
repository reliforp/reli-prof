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
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\Pointer;

final class ZendString implements Dereferencable
{
    public const ZEND_STRING_HEADER_SIZE = 24;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZendRefcountedH $gc;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $h;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $len;
    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<RawString>
     */
    public Pointer $val;

    /**
     * @param CastedCData<zend_string> $casted_cdata
     * @param Pointer<ZendString> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private int $offset_to_val,
        private Pointer $pointer,
    ) {
        unset($this->gc);
        unset($this->h);
        unset($this->len);
        unset($this->val);
    }

    public function __get(string $field_name)
    {
        return match ($field_name) {
            'gc' => $this->gc = new ZendRefcountedH(
                $this->casted_cdata->casted->gc,
            ),
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
        /**
         * @var CastedCData<zend_string> $casted_cdata
         * @var Pointer<ZendString> $pointer
         */
        // an almost safe assumption I think
        return new self($casted_cdata, self::ZEND_STRING_HEADER_SIZE, $pointer);
    }

    public function getPointer(): Pointer
    {
        return $this->pointer;
    }

    /**
     * @return Pointer<RawString>
     */
    public function getValuePointer(int $max_size = 256): Pointer
    {
        return new Pointer(
            RawString::class,
            $this->getPointer()->address + $this->offset_to_val,
            \min($this->len, $max_size)
        );
    }

    public function getSize(): int
    {
        return $this->len + self::ZEND_STRING_HEADER_SIZE;
    }

    public function toString(Dereferencer $dereferencer, int $max_size = 256): string
    {
        if ($this->len === 0) {
            return '';
        }
        return (string)$dereferencer->deref(
            $this->getValuePointer($max_size)
        );
    }
}
