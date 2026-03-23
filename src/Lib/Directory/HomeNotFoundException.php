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

namespace Reli\Lib\Directory;

final class HomeNotFoundException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Could not determine home directory:'
            . ' HOME environment variable is not set'
            . ' and no fallback is available'
        );
    }
}
