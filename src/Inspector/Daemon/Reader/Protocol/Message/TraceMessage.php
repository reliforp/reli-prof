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

namespace PhpProfiler\Inspector\Daemon\Reader\Protocol\Message;

final class TraceMessage
{
    /** @var string[] */
    public array $trace;

    /** @param string[] $trace */
    public function __construct(array $trace)
    {
        $this->trace = $trace;
    }
}
