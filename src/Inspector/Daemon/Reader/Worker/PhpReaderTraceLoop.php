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

use Generator;
use PhpProfiler\Inspector\Daemon\Dispatcher\TargetProcessDescriptor;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;
use PhpProfiler\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use PhpProfiler\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;
use PhpProfiler\Lib\PhpProcessReader\PhpMemoryReader\CallTraceReader;
use PhpProfiler\Lib\PhpProcessReader\TraceCache;
use PhpProfiler\Lib\Process\ProcessStopper\ProcessStopper;

use function is_null;
use function PhpProfiler\Lib\Defer\defer;

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
     * @throws \PhpProfiler\Lib\Elf\Parser\ElfParserException
     * @throws \PhpProfiler\Lib\Elf\Process\ProcessSymbolReaderException
     * @throws \PhpProfiler\Lib\Elf\Tls\TlsFinderException
     * @throws \PhpProfiler\Lib\Process\MemoryReader\MemoryReaderException
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
