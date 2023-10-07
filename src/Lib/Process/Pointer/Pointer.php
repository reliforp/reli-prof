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
use FFI\CType;
use Reli\Lib\PhpInternals\CastedCData;

/**
 * @template T of \Reli\Lib\Process\Pointer\Dereferencable
 */
class Pointer implements Dereferencable
{
    /**
     * @param class-string<T> $type
     * @param class-string<Dereferencable>|null $pointer_to_pointer_type
     */
    public function __construct(
        public string $type,
        public int $address,
        public int $size,
        public ?string $pointer_to_pointer_type = null,
    ) {
    }

    public function indexedAt(int $n): Pointer
    {
        return new Pointer(
            $this->type,
            $this->address + $n * $this->size,
            $this->size,
            $this->pointer_to_pointer_type,
        );
    }

    public function getCTypeNameOfType(): string
    {
        if ($this->pointer_to_pointer_type !== null) {
            return $this->pointer_to_pointer_type::getCTypeNameOfType() . '*';
        }
        return $this->type::getCTypeName();
    }

    /**
     * @param CastedCData<CData> $casted_cdata
     * @param Pointer<T> $pointer
     * @return T
     */
    public function fromCastedCDataOfType(
        CastedCData $casted_cdata,
        Pointer $pointer
    ): mixed {
        return $this->type::fromCastedCData($casted_cdata, $pointer);
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
        string $pointer_to_pointer_type = null
    ): self {
        /** @var CInteger $addr */
        $addr = \FFI::cast('long', $c_pointer);
        /** @psalm-trace $addr */
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
            $pointer_to_pointer_type,
        );
    }

    public static function getCTypeName(): string
    {
        return 'void*';
    }

    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        return new self(
            $pointer->pointer_to_pointer_type,
            $casted_cdata->casted->cdata,
            $pointer->size
        );
    }
}
