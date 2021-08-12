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

namespace PhpProfiler\Lib\Defer;

final class ScopeGuard
{
    public function __construct(
        private \Closure $callable,
        private ?ScopeGuard $chain = null,
    ) {
    }

    public function __destruct()
    {
        ($this->callable)();
        if (isset($this->chain)) {
            unset($this->chain);
        }
    }
}
