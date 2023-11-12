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

namespace Reli\Lib\PhpInternals\Types\C;

use FFI\CData;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Pointer;

final class RawString implements Dereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public string $value;

    /** @param CastedCData<CData> $cdata */
    public function __construct(
        private CastedCData $cdata,
        private int $len,
        private Pointer $pointer,
    ) {
        unset($this->value);
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function __get(string $field_name): string
    {
        return match ($field_name) {
            'value' => $this->value = substr(
                \FFI::string($this->cdata->casted),
                0,
                $this->len
            ),
        };
    }

    public static function getCTypeName(): string
    {
        return 'char[0]';
    }

    /** @param CastedCData<CData> $casted_cdata */
    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer
    ): static {
        return new self($casted_cdata, $pointer->size, $pointer);
    }

    public function getPointer(): Pointer
    {
        return $this->pointer;
    }
}
