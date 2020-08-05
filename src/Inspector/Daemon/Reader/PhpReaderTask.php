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

namespace PhpProfiler\Inspector\Daemon\Reader;

use Amp\Parallel\Sync\Channel;
use Generator;
use PhpProfiler\Inspector\Daemon\Dispatcher\Message\TraceMessage;
use PhpProfiler\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use PhpProfiler\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use PhpProfiler\Inspector\Settings\TargetProcessSettings\TargetProcessSettings;
use PhpProfiler\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;
use PhpProfiler\Lib\PhpProcessReader\PhpGlobalsFinder;
use PhpProfiler\Lib\PhpProcessReader\PhpMemoryReader\ExecutorGlobalsReader;

final class PhpReaderTask implements PhpReaderTaskInterface
{
    private PhpGlobalsFinder $php_globals_finder;
    private ExecutorGlobalsReader $executor_globals_reader;
    private ReaderLoopProvider $reader_loop_provider;

    public function __construct(
        PhpGlobalsFinder $php_globals_finder,
        ExecutorGlobalsReader $executor_globals_reader,
        ReaderLoopProvider $reader_loop_provider
    ) {
        $this->php_globals_finder = $php_globals_finder;
        $this->executor_globals_reader = $executor_globals_reader;
        $this->reader_loop_provider = $reader_loop_provider;
    }

    public function run(
        Channel $channel,
        TargetProcessSettings $target_process_settings,
        TraceLoopSettings $loop_settings,
        TargetPhpSettings $target_php_settings,
        GetTraceSettings $get_trace_settings
    ): Generator {
        $eg_address = $this->php_globals_finder->findExecutorGlobals($target_process_settings, $target_php_settings);

        $loop = $this->reader_loop_provider->getMainLoop(
            function () use (
                $channel,
                $get_trace_settings,
                $target_process_settings,
                $target_php_settings,
                $eg_address
            ): \Generator {
                $call_trace = $this->executor_globals_reader->readCallTrace(
                    $target_process_settings->pid,
                    $target_php_settings->php_version,
                    $eg_address,
                    $get_trace_settings->depth
                );
                yield $channel->send(
                    new TraceMessage($call_trace)
                );
            },
            $loop_settings
        );
        yield from $loop->invoke();
    }
}
