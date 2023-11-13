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

/**
 * @psalm-consistent-constructor
 */
class ZendReference implements Dereferencable
{
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
                ),
            ),
        };
    }

    public static function getCTypeName(): string
    {
        return 'zend_reference';
    }

    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        /**
         * @var CastedCData<\FFI\PhpInternals\zend_reference> $casted_cdata
         * @var Pointer<self> $pointer
         */
        return new static($casted_cdata, $pointer);
    }

    public function getPointer(): Pointer
    {
        return $this->pointer;
    }
}
