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
use PhpProfiler\Lib\PhpInternals\CastedCData;

interface Dereferencable
{
    public static function getCTypeName(): string;

    /**
     * @template T of CData
     * @param CastedCData<T> $casted_cdata
     * @param Pointer<self> $pointer
     */
    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer
    ): static;
}
