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

namespace PhpProfiler\Lib\Loop\LoopMiddleware;

use PhpProfiler\Lib\Loop\LoopMiddlewareInterface;

final class CallableMiddleware implements LoopMiddlewareInterface
{
    private $callable;

    public function __construct(callable $callable)
    {
        $this->callable = $callable;
    }

    public function invoke(): bool
    {
        /** @var bool */
        return ($this->callable)();
    }
}
