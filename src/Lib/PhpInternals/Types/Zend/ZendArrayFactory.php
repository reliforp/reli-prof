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
use Reli\Lib\Process\Pointer\Pointer;

/** @psalm-require-implements PhpVersionAware */
trait ZendArrayFactory
{
    protected function newZendArray(CastedCData $casted_cdata, Pointer $pointer): ZendArray
    {
        /** @var class-string<ZendArray> $class */
        $class = VersionedTypeMap::resolve(static::getPhpVersion(), ZendArray::class);
        return $class::fromCastedCData($casted_cdata, $pointer);
    }
}
