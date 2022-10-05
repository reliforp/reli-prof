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

namespace PhpProfiler\Inspector\Output\TraceOutput;

use PhpProfiler\Inspector\Output\OutputChannel\OutputChannel;
use PhpProfiler\Inspector\Output\TraceFormatter\CallTraceFormatter;
use PhpProfiler\Lib\PhpProcessReader\CallTrace;

final class FormattedTraceOutput implements TraceOutput
{
    public function __construct(
        private OutputChannel $output_channel,
        private CallTraceFormatter $call_trace_formatter,
    ) {
    }

    public function output(CallTrace $call_trace): void
    {
        $this->output_channel->output(
            $this->call_trace_formatter->format($call_trace)
        );
    }
}
