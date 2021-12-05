<?php

/**
 * This file is part of the sj-i/ package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Inspector\Output\TopLike;

use PhpProfiler\Lib\DateTime\Clock;
use PhpProfiler\Lib\PhpProcessReader\CallTrace;

final class TopLikeFormatter
{
    private Stat $stat;
    private \DateTimeImmutable $previous;

    public function __construct(
        private string $trace_target,
        private Outputter $outputter,
        private Clock $clock,
    ) {
        $this->stat = new Stat();
        $this->previous = $this->clock->now();
    }

    public function format(CallTrace $call_trace): void
    {
        $this->stat->addTrace($call_trace);
        $now = $this->clock->now();
        if ($now >= $this->previous->modify('+1 second')) {
            $this->outputter->display($this->trace_target, $this->stat);
            $this->previous = $now;
        }
    }
}
