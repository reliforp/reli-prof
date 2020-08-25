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

namespace PhpProfiler\Inspector\Daemon\Reader\Controller;

use Amp\Promise;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\DetachWorkerMessage;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;
use PhpProfiler\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use PhpProfiler\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use PhpProfiler\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;

interface PhpReaderControllerInterface
{
    public function start(): Promise;

    public function isRunning(): bool;

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

    /**
     * @return Promise<TraceMessage|DetachWorkerMessage>
     */
    public function receiveTraceOrDetachWorker(): Promise;
}
