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

use Reli\Inspector\Watch\HeapStats;
use Reli\Inspector\Watch\TriggerEvent;
use Reli\Inspector\Watch\WatchContext;
use Reli\Lib\Process\ProcessSpecifier;

final class ExecAction implements ActionInterface
{
    public function __construct(
        private string $command,
    ) {
    }

    #[\Override]
    public function name(): string
    {
        return 'exec';
    }

    #[\Override]
    public function execute(
        TriggerEvent $event,
        ProcessSpecifier $process,
        WatchContext $context,
    ): void {
        $env = [
            'RELI_WATCH_PID' => (string)$process->pid,
            'RELI_WATCH_TRIGGER' => $event->trigger_name,
            'RELI_WATCH_MEMORY_USAGE' => (string)$context->heap_stats->size,
            'RELI_WATCH_MEMORY_PEAK' => (string)$context->heap_stats->peak,
            'RELI_WATCH_TIMESTAMP' => date('c', (int)$event->timestamp),
        ];

        // Fire-and-forget: redirect stdout/stderr to /dev/null
        // so proc_open doesn't block the monitoring loop.
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        $proc = proc_open(
            $this->command,
            $descriptors,
            $pipes,
            null,
            $env + getenv(),
        );

        if ($proc === false) {
            return;
        }

        // Don't wait for completion — detach immediately.
        // The child process runs independently.
        // proc_close() would block, so we intentionally skip it.
        // PHP will clean up the process handle on GC.
    }
}
