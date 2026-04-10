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
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader;
use Reli\Lib\PhpProcessReader\CallTraceReader\TraceCache;
use Reli\Lib\Process\ProcessSpecifier;

final class TraceAction implements ActionInterface
{
    /**
     * @param value-of<\Reli\Lib\PhpInternals\ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     * @param int $eg_address
     * @param int $sg_address
     * @param int $depth
     */
    public function __construct(
        private CallTraceReader $call_trace_reader,
        private TraceOutput $trace_output,
        private string $php_version,
        private int $eg_address,
        private int $sg_address,
        private int $depth,
    ) {
    }

    #[\Override]
    public function name(): string
    {
        return 'trace-once';
    }

    #[\Override]
    public function execute(
        TriggerEvent $event,
        ProcessSpecifier $process,
        WatchContext $context,
    ): void {
        $call_trace = $context->call_trace;
        if ($call_trace === null) {
            $call_trace = $this->call_trace_reader->readCallTrace(
                $process->pid,
                $this->php_version,
                $this->eg_address,
                $this->sg_address,
                $this->depth,
                new TraceCache(),
            );
        }
        if ($call_trace !== null) {
            $this->trace_output->output($call_trace);
        }
    }
}
