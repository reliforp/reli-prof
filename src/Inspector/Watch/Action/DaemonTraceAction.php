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

use Reli\Inspector\Output\TraceOutput\TraceOutput;
use Reli\Inspector\Watch\TriggerEvent;
use Reli\Inspector\Watch\WatchContext;
use Reli\Lib\Process\ProcessSpecifier;

/**
 * Trace action for daemon mode.
 * Outputs the call trace already available in WatchContext (received from worker).
 */
final class DaemonTraceAction implements ActionInterface
{
    public function __construct(
        private TraceOutput $trace_output,
    ) {
    }

    #[\Override]
    public function name(): string
    {
        return 'trace';
    }

    #[\Override]
    public function execute(
        TriggerEvent $event,
        ProcessSpecifier $process,
        WatchContext $context,
    ): void {
        if ($context->call_trace !== null) {
            $this->trace_output->output($context->call_trace);
        }
    }
}
