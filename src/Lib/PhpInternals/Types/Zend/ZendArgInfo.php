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

/** @psalm-consistent-constructor  */
class ZendArgInfo implements Dereferencable
{
    /** @var Pointer<ZendString>|null */
    public ?Pointer $name;

    /**
     * @param CastedCData<\FFI\PhpInternals\zend_arg_info> $casted_cdata
     * @param Pointer<ZendArgInfo> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer
    ) {
        unset($this->name);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'name' => $this->name = $this->casted_cdata->casted->name !== null
                ? Pointer::fromCData(
                    ZendString::class,
                    $this->casted_cdata->casted->name,
                )
                : null
            ,
        };
    }

    public static function getCTypeName(): string
    {
        return 'zend_arg_info';
    }

    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer
    ): static {
        /**
         * @var CastedCData<\FFI\PhpInternals\zend_arg_info> $casted_cdata
         * @var Pointer<ZendArgInfo> $pointer
         */
        return new static($casted_cdata, $pointer);
    }

    /** @return Pointer<ZendArgInfo> */
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }
}
