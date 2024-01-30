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

use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Pointer;

final class RawChar implements Dereferencable
{
    public string $value;

    /** @param CastedCData<\FFI\CChar> $casted_cdata */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        $this->value = $this->casted_cdata->casted->cdata;
    }

    public static function getCTypeName(): string
    {
        return 'char';
    }

    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer
    ): static {
        /** @var CastedCData<\FFI\CChar> $casted_cdata */
        return new self($casted_cdata, $pointer);
    }

    public function getPointer(): Pointer
    {
        return $this->pointer;
    }
}
