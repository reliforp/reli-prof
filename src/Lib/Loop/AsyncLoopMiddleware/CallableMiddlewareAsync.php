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

namespace Reli\Lib\Loop\AsyncLoopMiddleware;

use Reli\Lib\Loop\AsyncLoopMiddlewareInterface;

final class CallableMiddlewareAsync implements AsyncLoopMiddlewareInterface
{
    private $callable;

    public function __construct(callable $callable)
    {
        $this->callable = $callable;
    }

    public function invoke(): \Generator
    {
        /** @var bool */
        yield from ($this->callable)();
    }
}
