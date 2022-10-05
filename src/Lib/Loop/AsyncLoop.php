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

namespace Reli\Lib\Loop;

final class AsyncLoop
{
    public function __construct(
        private AsyncLoopMiddlewareInterface $process
    ) {
    }

    public function invoke(): \Generator
    {
        while (1) {
            $result = $this->process->invoke();
            if (!$result->valid()) {
                break;
            }
            yield from $result;
        }
    }
}
