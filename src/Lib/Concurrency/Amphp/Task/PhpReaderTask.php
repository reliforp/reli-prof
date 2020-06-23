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

namespace PhpProfiler\Lib\Concurrency\Amphp\Task;

use Amp\Parallel\Sync\Channel;
use Generator;
use PhpProfiler\Inspector\Settings\GetTraceSettings;
use PhpProfiler\Inspector\Settings\TargetPhpSettings;
use PhpProfiler\Inspector\Settings\TargetProcessSettings;
use PhpProfiler\Inspector\Settings\TraceLoopSettings;
use PhpProfiler\Inspector\Daemon\Worker\ReaderLoopProvider;
use PhpProfiler\Lib\PhpProcessReader\PhpGlobalsFinder;
use PhpProfiler\Lib\PhpProcessReader\PhpMemoryReader\ExecutorGlobalsReader;

final class PhpReaderTask
{
    private Channel $channel;
    private PhpGlobalsFinder $php_globals_finder;
    private ExecutorGlobalsReader $executor_globals_reader;
    private ReaderLoopProvider $reader_loop_provider;

    public function __construct(
        Channel $channel,
        PhpGlobalsFinder $php_globals_finder,
        ExecutorGlobalsReader $executor_globals_reader,
        ReaderLoopProvider $reader_loop_provider
    ) {
        $this->channel = $channel;
        $this->php_globals_finder = $php_globals_finder;
        $this->executor_globals_reader = $executor_globals_reader;
        $this->reader_loop_provider = $reader_loop_provider;
    }

    public function run(
        TraceLoopSettings $loop_settings,
        TargetProcessSettings $target_process_settings,
        TargetPhpSettings $target_php_settings,
        GetTraceSettings $get_trace_settings
    ): Generator {
        $eg_address = $this->php_globals_finder->findExecutorGlobals($target_process_settings, $target_php_settings);

        $loop = $this->reader_loop_provider->getMainLoop(
            function () use (
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
                yield $this->channel->send(join(PHP_EOL, $call_trace) . PHP_EOL);
            },
            $loop_settings
        );
        yield from $loop->invoke();
    }
}
