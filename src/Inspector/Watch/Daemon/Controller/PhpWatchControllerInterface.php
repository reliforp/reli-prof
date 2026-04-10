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

namespace Reli\Inspector\Watch\Daemon\Controller;

use Reli\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;
use Reli\Inspector\Settings\WatchSettings\WatchSettings;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchWorkerMessage;

interface PhpWatchControllerInterface
{
    public function start(): void;

    public function isRunning(): bool;

    public function sendSettings(
        WatchSettings $watch_settings,
        TraceLoopSettings $loop_settings,
        GetTraceSettings $get_trace_settings,
    ): void;

    public function sendAttach(
        \Reli\Inspector\Watch\Daemon\Searcher\WatchTargetDescriptor $process_descriptor,
    ): void;

    public function receiveMessage(): WatchWorkerMessage;
}
