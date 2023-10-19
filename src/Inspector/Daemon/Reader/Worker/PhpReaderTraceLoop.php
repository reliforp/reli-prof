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

namespace Reli\Inspector\Daemon\Reader\Worker;

use Generator;
use Reli\Inspector\Daemon\Dispatcher\TargetProcessDescriptor;
use Reli\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;
use Reli\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader;
use Reli\Lib\PhpProcessReader\CallTraceReader\TraceCache;
use Reli\Lib\Process\ProcessStopper\ProcessStopper;

use function is_null;
use function Reli\Lib\Defer\defer;

final class PhpReaderTraceLoop implements PhpReaderTraceLoopInterface
{
    public function __construct(
        private CallTraceReader $executor_globals_reader,
        private ReaderLoopProvider $reader_loop_provider,
        private ProcessStopper $process_stopper,
    ) {
    }

    /**
     * @return Generator<TraceMessage>
     * @throws \Reli\Lib\Elf\Parser\ElfParserException
     * @throws \Reli\Lib\Elf\Process\ProcessSymbolReaderException
     * @throws \Reli\Lib\Elf\Tls\TlsFinderException
     * @throws \Reli\Lib\Process\MemoryReader\MemoryReaderException
     */
    public function run(
        TraceLoopSettings $loop_settings,
        TargetProcessDescriptor $target_process_descriptor,
        GetTraceSettings $get_trace_settings
    ): Generator {
        $trace_cache = new TraceCache();
        $loop = $this->reader_loop_provider->getMainLoop(
            function () use (
                $get_trace_settings,
                $target_process_descriptor,
                $loop_settings,
                $trace_cache,
            ): Generator {
                if ($loop_settings->stop_process and $this->process_stopper->stop($target_process_descriptor->pid)) {
                    defer($_, fn () => $this->process_stopper->resume($target_process_descriptor->pid));
                }
                $call_trace = $this->executor_globals_reader->readCallTrace(
                    $target_process_descriptor->pid,
                    $target_process_descriptor->php_version,
                    $target_process_descriptor->eg_address,
                    $target_process_descriptor->sg_address,
                    $get_trace_settings->depth,
                    $trace_cache
                );
                if (is_null($call_trace)) {
                    return;
                }
                yield new TraceMessage($call_trace);
            },
            $loop_settings
        );
        /** @var Generator<TraceMessage> */
        $loop_process = $loop->invoke();
        yield from $loop_process;
    }
}
