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

namespace PhpProfiler\Lib\Loop;

final class Loop
{
    private LoopProcessInterface $process;

    public function __construct(LoopProcessInterface $process)
    {
        $this->process = $process;
    }

    public function invoke(): void
    {
        while (1) {
            if (!$this->process->invoke()) {
                break;
            }
        }
    }
}
