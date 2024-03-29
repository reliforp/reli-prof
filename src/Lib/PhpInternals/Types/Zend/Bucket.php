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
class Bucket implements Dereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public Zval $val;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $h;
    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZendString>|null
     */
    public ?Pointer $key;

    /**
     * @param CastedCData<\FFI\PhpInternals\Bucket> $casted_cdata
     * @param Pointer<Bucket> $pointer
     */
    public function __construct(
        protected CastedCData $casted_cdata,
        protected Pointer $pointer,
    ) {
        unset($this->val);
        unset($this->h);
        unset($this->key);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'val' => $this->val = new Zval(
                new CastedCData(
                    $this->casted_cdata->casted->val,
                    $this->casted_cdata->casted->val,
                ),
                new Pointer(
                    Zval::class,
                    $this->pointer->address
                    +
                    \FFI::typeof($this->casted_cdata->casted)->getStructFieldOffset('val'),
                    \FFI::sizeof($this->casted_cdata->casted->val),
                )
            ),
            'h' => $this->h = 0xFFFF_FFFF & $this->casted_cdata->casted->h,
            'key' => $this->key = $this->casted_cdata->casted->key !== null ? Pointer::fromCData(
                ZendString::class,
                $this->casted_cdata->casted->key
            )
            : null
            ,
        };
    }

    public static function getCTypeName(): string
    {
        return 'Bucket';
    }

    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer
    ): static {
        /**
         * @var CastedCData<\FFI\PhpInternals\Bucket> $casted_cdata
         * @var Pointer<Bucket> $pointer
         */
        return new static($casted_cdata, $pointer);
    }

    /** @return Pointer<Bucket> */
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }
}
