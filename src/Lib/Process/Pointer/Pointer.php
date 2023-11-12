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

namespace Reli\Lib\Process\Pointer;

use FFI\CData;
use FFI\CInteger;
use FFI\CPointer;

/** @template-covariant T of Dereferencable */
class Pointer
{
    /** @param class-string<T> $type */
    public function __construct(
        public string $type,
        public int $address,
        public int $size,
    ) {
    }

    /** @return self<T> */
    public function indexedAt(int $n): Pointer
    {
        return new Pointer(
            $this->type,
            $this->address + $n * $this->size,
            $this->size,
        );
    }

    public function getCTypeNameOfType(): string
    {
        return $this->type::getCTypeName();
    }

    /**
     * @template TType of \Reli\Lib\Process\Pointer\Dereferencable
     * @param class-string<TType> $type
     * @param CPointer $c_pointer
     * @return Pointer<TType>
     */
    public static function fromCData(
        string $type,
        CData $c_pointer,
    ): self {
        /** @var CInteger $addr */
        $addr = \FFI::cast('long', $c_pointer);
        $ctype = \FFI::typeof($c_pointer)->getPointerType();
        return new self(
            $type,
            $addr->cdata,
            \FFI::sizeof($ctype),
        );
    }
}
