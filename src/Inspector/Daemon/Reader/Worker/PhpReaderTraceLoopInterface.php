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

namespace PhpProfiler\Inspector\Daemon\Reader\Worker;

use Generator;
use PhpProfiler\Inspector\Daemon\Dispatcher\TargetProcessDescriptor;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;
use PhpProfiler\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use PhpProfiler\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;

interface PhpReaderTraceLoopInterface
{
    /** @return Generator<TraceMessage> */
    public function run(
        TraceLoopSettings $loop_settings,
        TargetProcessDescriptor $target_process_descriptor,
        GetTraceSettings $get_trace_settings
    ): Generator;
}
