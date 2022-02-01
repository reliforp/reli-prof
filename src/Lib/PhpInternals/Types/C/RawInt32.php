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

namespace PhpProfiler\Lib\PhpInternals\Types\C;

use FFI\CInteger;
use PhpProfiler\Lib\PhpInternals\CastedCData;
use PhpProfiler\Lib\Process\Pointer\Dereferencable;
use PhpProfiler\Lib\Process\Pointer\Pointer;

final class RawInt32 implements Dereferencable
{
    public int $value;

    /** @param CastedCData<CInteger> $casted_cdata */
    public function __construct(
        private CastedCData $casted_cdata,
    ) {
        $this->value = $this->casted_cdata->casted->cdata;
    }

    public static function getCTypeName(): string
    {
        return 'int32_t';
    }

    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer
    ): static {
        /** @var CastedCData<CInteger> $casted_cdata */
        return new self($casted_cdata);
    }
}
