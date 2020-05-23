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

namespace PhpProfiler\Lib\Timer\LoopProcess;

use PhpProfiler\Lib\Timer\LoopProcessInterface;

final class CallableLoop implements LoopProcessInterface
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
