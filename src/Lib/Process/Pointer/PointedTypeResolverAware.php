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

use Reli\Lib\PhpInternals\CastedCData;

/** @psalm-suppress MethodSignatureMismatch intentionally requires resolver param */
interface PointedTypeResolverAware extends Dereferencable
{
    #[\Override]
    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer,
        PointedTypeResolver $pointed_type_resolver,
    ): static;
}
