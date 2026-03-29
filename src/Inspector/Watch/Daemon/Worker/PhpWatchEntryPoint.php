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

namespace Reli\Inspector\Watch\Daemon\Worker;

use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchDetachMessage;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchTriggerMessage;
use Reli\Inspector\Watch\Daemon\Protocol\PhpWatchWorkerProtocolInterface;
use Reli\Inspector\Watch\HeapStats;
use Reli\Inspector\Watch\HeapStatsReader;
use Reli\Inspector\Watch\Trigger\ExceptionDetectionTrigger;
use Reli\Inspector\Watch\Trigger\FunctionDetectionTrigger;
use Reli\Inspector\Watch\Trigger\MemoryGrowthRateTrigger;
use Reli\Inspector\Watch\Trigger\MemoryLimitTrigger;
use Reli\Inspector\Watch\Trigger\MemoryPeakTrigger;
use Reli\Inspector\Watch\Trigger\TraceDepthTrigger;
use Reli\Inspector\Watch\Trigger\TriggerInterface;
use Reli\Inspector\Watch\WatchContext;
use Reli\Lib\Amphp\WorkerEntryPointInterface;
use Reli\Lib\Log\Log;
use Reli\Lib\Loop\LoopCondition\InfiniteLoopCondition;
use Reli\Lib\Loop\LoopCondition\LoopConditionInterface;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader;
use Reli\Lib\PhpProcessReader\CallTraceReader\TraceCache;
use Reli\Lib\Process\ProcessSpecifier;

final class PhpWatchEntryPoint implements WorkerEntryPointInterface
{
    public function __construct(
        private HeapStatsReader $heap_stats_reader,
        private CallTraceReader $call_trace_reader,
        private PhpWatchWorkerProtocolInterface $protocol,
        private LoopConditionInterface $loop_condition = new InfiniteLoopCondition(),
    ) {
    }

    #[\Override]
    public function run(): void
    {
        $settings_message = $this->protocol->receiveSettings();
        Log::debug('watch worker: settings received');

        $watch_settings = $settings_message->watch_settings;
        $get_trace_settings = $settings_message->get_trace_settings;

        // Build triggers from settings
        $triggers = $this->buildTriggers($watch_settings);
        $needs_call_trace = false;
        foreach ($triggers as $trigger) {
            if ($trigger->requiresCallTrace()) {
                $needs_call_trace = true;
                break;
            }
        }

        $poll_sleep_us = $watch_settings->poll_interval_ms * 1000;

        while ($this->loop_condition->shouldContinue()) {
            $attach_message = $this->protocol->receiveAttach();
            $descriptor = $attach_message->process_descriptor;
            Log::debug('watch worker: attached', ['pid' => $descriptor->pid]);

            $process_specifier = new ProcessSpecifier($descriptor->pid);
            /** @var \Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings $target_php_settings */
            $target_php_settings = new \Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings(
                php_regex: '/.+/',
                libpthread_regex: '/.+/',
                php_version: $descriptor->php_version,
                php_path: null,
                libpthread_path: null,
            );

            $previous_context = null;
            $trace_cache = new TraceCache();

            try {
                while ($this->loop_condition->shouldContinue()) {
                    $now = microtime(true);

                    // Read heap stats (lightweight)
                    $heap_stats = $this->heap_stats_reader->read(
                        $process_specifier,
                        $target_php_settings,
                        $descriptor->eg_address,
                    );

                    // Read call trace if needed
                    $call_trace = null;
                    if ($needs_call_trace) {
                        $call_trace = $this->call_trace_reader->readCallTrace(
                            $descriptor->pid,
                            $descriptor->php_version,
                            $descriptor->eg_address,
                            $descriptor->sg_address,
                            $get_trace_settings->depth,
                            $trace_cache,
                        );
                    }

                    $context = new WatchContext(
                        pid: $descriptor->pid,
                        heap_stats: $heap_stats,
                        call_trace: $call_trace,
                        has_exception: null,
                        timestamp: $now,
                        previous: $previous_context,
                    );

                    // Evaluate triggers
                    foreach ($triggers as $trigger) {
                        $event = $trigger->evaluate($context);
                        if ($event !== null) {
                            $this->protocol->sendTrigger(new WatchTriggerMessage(
                                pid: $descriptor->pid,
                                event: $event,
                                heap_stats: $heap_stats,
                                call_trace: $call_trace,
                            ));
                        }
                    }

                    $previous_context = $context;
                    usleep($poll_sleep_us);
                }
            } catch (\Throwable $e) {
                Log::debug('watch worker: exception', [
                    'pid' => $descriptor->pid,
                    'exception' => $e->getMessage(),
                ]);
            }

            $this->protocol->sendDetach(new WatchDetachMessage($descriptor->pid));
            Log::debug('watch worker: detached', ['pid' => $descriptor->pid]);
        }
    }

    /**
     * @return list<TriggerInterface>
     */
    private function buildTriggers(\Reli\Inspector\Settings\WatchSettings\WatchSettings $settings): array
    {
        $triggers = [];

        if ($settings->memory_limit_bytes !== null) {
            $triggers[] = new MemoryLimitTrigger($settings->memory_limit_bytes);
        }
        if ($settings->memory_growth_rate !== null) {
            [$bytes, $seconds] = MemoryGrowthRateTrigger::parseRate($settings->memory_growth_rate);
            $triggers[] = new MemoryGrowthRateTrigger($bytes, $seconds);
        }
        if ($settings->memory_peak_watch) {
            $triggers[] = new MemoryPeakTrigger();
        }
        if ($settings->watch_function !== null) {
            $triggers[] = new FunctionDetectionTrigger($settings->watch_function);
        }
        if ($settings->trace_depth_limit !== null) {
            $triggers[] = new TraceDepthTrigger($settings->trace_depth_limit);
        }
        if ($settings->on_exception) {
            $triggers[] = new ExceptionDetectionTrigger();
        }

        return $triggers;
    }
}
