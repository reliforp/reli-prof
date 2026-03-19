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

namespace Reli\Lib\PhpInternals\Types\Zend\V74;

use Reli\Lib\PhpInternals\Types\Zend\ZendExecutorGlobals as BaseZendExecutorGlobals;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\Pointer\Dereferencable;

final class ZendExecutorGlobals extends BaseZendExecutorGlobals implements Dereferencable
{
    public static function getPhpVersion(): string
    {
        return ZendTypeReader::V74;
    }
}
