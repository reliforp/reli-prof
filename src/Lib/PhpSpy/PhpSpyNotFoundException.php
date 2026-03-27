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

namespace Reli\Lib\PhpSpy;

final class PhpSpyNotFoundException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'phpspy binary not found. Install it with "phpspy:install" or specify the path with --phpspy-path.'
        );
    }
}
