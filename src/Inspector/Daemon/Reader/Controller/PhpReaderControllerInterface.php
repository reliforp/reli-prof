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

namespace Reli\Inspector\Daemon\Reader\Controller;

use Amp\Promise;
use Reli\Inspector\Daemon\Dispatcher\TargetProcessDescriptor;
use Reli\Inspector\Daemon\Reader\Protocol\Message\DetachWorkerMessage;
use Reli\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;
use Reli\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;

interface PhpReaderControllerInterface
{
    public function start(): Promise;

    public function isRunning(): bool;

    /** @return Promise<int> */
    public function sendSettings(
        TraceLoopSettings $loop_settings,
        GetTraceSettings $get_trace_settings
    ): Promise;

    /** @return Promise<int> */
    public function sendAttach(TargetProcessDescriptor $process_descriptor): Promise;

    /** @return Promise<TraceMessage|DetachWorkerMessage> */
    public function receiveTraceOrDetachWorker(): Promise;
}
