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

namespace PhpProfiler\Inspector\Daemon\Reader\Context;

use Amp\Promise;
use PhpProfiler\Inspector\Daemon\Dispatcher\Message\DetachWorkerMessage;
use PhpProfiler\Inspector\Daemon\Dispatcher\Message\TraceMessage;
use PhpProfiler\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use PhpProfiler\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use PhpProfiler\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;

interface PhpReaderContextInterface
{
    public function start(): Promise;

    /**
     * @param TargetPhpSettings $target_php_settings
     * @param TraceLoopSettings $loop_settings
     * @param GetTraceSettings $get_trace_settings
     * @return Promise<int>
     */
    public function sendSettings(
        TargetPhpSettings $target_php_settings,
        TraceLoopSettings $loop_settings,
        GetTraceSettings $get_trace_settings
    ): Promise;

    /**
     * @param int $pid
     * @return Promise<int>
     */
    public function sendAttach(int $pid): Promise;

    public function isRunning(): bool;

    /**
     * @return Promise<TraceMessage|DetachWorkerMessage>
     */
    public function receiveTrace(): Promise;
}
