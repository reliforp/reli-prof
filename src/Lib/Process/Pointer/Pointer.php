<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Lib\Process\Pointer;

use FFI\CData;
use FFI\CInteger;
use FFI\CPointer;
use FFI\CType;
use PhpProfiler\Lib\PhpInternals\CastedCData;

/**
 * @template T of \PhpProfiler\Lib\Process\Pointer\Dereferencable
 */
class Pointer
{
    /** @param class-string<T> $type */
    public function __construct(
        public string $type,
        public int $address,
        public int $size,
    ) {
    }

    public function indexedAt(int $n): Pointer {
        return new Pointer(
            $this->type,
            $n * $this->size + $this->address,
            $this->size,
        );
    }

    public function getCTypeName(): string
    {
        return $this->type::getCTypeName();
    }

    /**
     * @param CastedCData<CData> $casted_cdata
     * @param Pointer<T> $pointer
     * @return T
     */
    public function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer
    ): mixed {
        return $this->type::fromCastedCData($casted_cdata, $pointer);
    }

    /**
     * @template TType of \PhpProfiler\Lib\Process\Pointer\Dereferencable
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
        /**
         * @psalm-suppress InaccessibleMethod
         * @var CData $element
         */
        $element = $c_pointer[0];
        /** @param CType $ctype */
        $ctype = \FFI::typeof($element);
        return new self(
            $type,
            $addr->cdata,
            \FFI::sizeof($ctype),
        );
    }
}
