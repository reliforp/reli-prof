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

namespace Reli\Inspector\Watch\Action;

use Reli\Inspector\Watch\TraceSession;
use Reli\Inspector\Watch\TriggerEvent;
use Reli\Inspector\Watch\WatchContext;
use Reli\Lib\Process\ProcessSpecifier;

/**
 * Stops the continuous trace recording session.
 *
 * Intended for use as an on-exit action, paired with
 * ContinuousTraceAction on-enter.
 */
final class StopTraceAction implements ActionInterface
{
    public function __construct(
        private TraceSession $trace_session,
    ) {
    }

    #[\Override]
    public function name(): string
    {
        return 'stop-trace';
    }

    #[\Override]
    public function execute(
        TriggerEvent $event,
        ProcessSpecifier $process,
        WatchContext $context,
    ): void {
        $this->trace_session->stop();
    }
}
