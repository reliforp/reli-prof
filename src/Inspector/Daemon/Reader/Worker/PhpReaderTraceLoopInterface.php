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

use Amp\Parallel\Sync\Channel;
use Generator;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\PhpReaderWorkerProtocolInterface;
use PhpProfiler\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use PhpProfiler\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use PhpProfiler\Inspector\Settings\TargetProcessSettings\TargetProcessSettings;
use PhpProfiler\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;

interface PhpReaderTraceLoopInterface
{
    /**
     * @param TargetProcessSettings $target_process_settings
     * @param TraceLoopSettings $loop_settings
     * @param TargetPhpSettings $target_php_settings
     * @param GetTraceSettings $get_trace_settings
     * @return Generator<TraceMessage>
     */
    public function run(
        TargetProcessSettings $target_process_settings,
        TraceLoopSettings $loop_settings,
        TargetPhpSettings $target_php_settings,
        GetTraceSettings $get_trace_settings
    ): Generator;
}
