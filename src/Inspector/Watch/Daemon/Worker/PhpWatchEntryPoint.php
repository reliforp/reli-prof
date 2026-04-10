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

use Reli\Inspector\Watch\CooldownManager;
use Reli\Inspector\Watch\CpuUsageReader;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchDetachMessage;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchTraceNotifyMessage;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchTriggerExitMessage;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchTriggerMessage;
use Reli\Inspector\Watch\Daemon\Protocol\PhpWatchWorkerProtocolInterface;
use Reli\Inspector\Watch\HeapStats;
use Reli\Inspector\Watch\HeapStatsReader;
use Reli\Inspector\Watch\RssReader;
use Reli\Inspector\Watch\TraceSession;
use Reli\Inspector\Watch\TriggerPhase;
use Reli\Inspector\Watch\TriggerStateTracker;
use Reli\Inspector\Watch\VariableReader;
use Reli\Inspector\Watch\VariableSpec;
use Reli\Inspector\Watch\Trigger\CpuUsageTrigger;
use Reli\Inspector\Watch\Trigger\FunctionDetectionTrigger;
use Reli\Inspector\Watch\Trigger\MemoryGrowthRateTrigger;
use Reli\Inspector\Watch\Trigger\MemoryUsageTrigger;
use Reli\Inspector\Watch\Trigger\MemoryPeakTrigger;
use Reli\Inspector\Watch\Trigger\RssUsageTrigger;
use Reli\Inspector\Watch\Trigger\TraceDepthTrigger;
use Reli\Inspector\Watch\Trigger\TriggerInterface;
use Reli\Inspector\Watch\Trigger\VariableValueTrigger;
use Reli\Inspector\Watch\WatchContext;
use Reli\Lib\Amphp\WorkerEntryPointInterface;
use Reli\Lib\Directory\AppDirectory;
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
        private RssReader $rss_reader,
        private CpuUsageReader $cpu_usage_reader,
        private CallTraceReader $call_trace_reader,
        private VariableReader $variable_reader,
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
        if ($watch_settings->memory_limit !== null) {
            ini_set('memory_limit', $watch_settings->memory_limit);
        }
        $get_trace_settings = $settings_message->get_trace_settings;

        // Build triggers from settings
        $triggers = $this->buildTriggers($watch_settings);
        $needs_call_trace = false;
        $needs_rss = false;
        $needs_cpu = false;
        /** @var list<VariableSpec> $var_specs */
        $var_specs = [];
        foreach ($triggers as $trigger) {
            if ($trigger->requiresCallTrace()) {
                $needs_call_trace = true;
            }
            if ($trigger instanceof RssUsageTrigger) {
                $needs_rss = true;
            }
            if ($trigger instanceof CpuUsageTrigger) {
                $needs_cpu = true;
            }
            if ($trigger instanceof VariableValueTrigger) {
                $var_specs[] = new VariableSpec(
                    scope: $trigger->scope,
                    var_name: $trigger->var_name,
                    lookup_key: $trigger->lookup_key,
                );
            }
        }

        // Determine if continuous tracing is requested
        $has_continuous_trace = \in_array('trace', $watch_settings->on_enter_actions, true);
        // When tracing, always read call traces regardless of trigger requirements
        $needs_call_trace_for_triggers = $needs_call_trace;

        // Worker handles cooldown/backoff per-process.
        // Global max_triggers is enforced by the controller (not here),
        // since it must be a single counter across all workers.
        $cooldown = new CooldownManager(
            base_cooldown_seconds: (float)$watch_settings->cooldown_seconds,
            backoff_multiplier: $watch_settings->backoff_multiplier,
            backoff_max_seconds: (float)$watch_settings->backoff_max_seconds,
            max_triggers_per_hour: $watch_settings->max_triggers_per_hour,
            max_triggers: 0, // unlimited — controller enforces the global limit
        );

        $poll_sleep_us = $watch_settings->poll_interval_ms * 1000;
        $trace_sleep_us = $watch_settings->trace_interval_ms * 1000;

        // Lifecycle state tracking for on-enter/on-exit
        $has_lifecycle = count($watch_settings->on_enter_actions) > 0
            || count($watch_settings->on_exit_actions) > 0;
        $state_tracker = new TriggerStateTracker();

        // Ensure output directory exists for trace files
        if ($has_continuous_trace) {
            AppDirectory::ensureDirectoryExists($watch_settings->action_output_dir);
        }

        while ($this->loop_condition->shouldContinue()) {
            $attach_message = $this->protocol->receiveAttach();
            $descriptor = $attach_message->process_descriptor;
            Log::debug('watch worker: attached', ['pid' => $descriptor->pid]);

            $process_specifier = new ProcessSpecifier($descriptor->pid);
            /** @var \Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings<value-of<\Reli\Lib\PhpInternals\ZendTypeReader::ALL_SUPPORTED_VERSIONS>> $target_php_settings */
            $target_php_settings = new \Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings(
                php_regex: '/.+/',
                libpthread_regex: '/.+/',
                php_version: $descriptor->php_version,
                php_path: null,
                libpthread_path: null,
            );

            $previous_context = null;
            $trace_cache = new TraceCache();
            $consecutive_failures = 0;
            $max_consecutive_failures = 10;

            // Per-process trace session (worker-local .rbt file)
            $trace_session = $has_continuous_trace
                ? new TraceSession(
                    $watch_settings->action_output_dir,
                    $watch_settings->trace_interval_ms * 1000,
                )
                : null;

            // Prime CPU reader for this process
            if ($needs_cpu) {
                $this->cpu_usage_reader->read($descriptor->pid);
            }

            try {
                while ($this->loop_condition->shouldContinue()) {
                    $loop_start = \hrtime(true);
                    $now = microtime(true);
                    $call_trace = null;
                    $rss_bytes = null;

                    // Read process state. Skip poll on failure
                    // (target may be between requests).
                    try {
                        $heap_stats = $this->heap_stats_reader->read(
                            $process_specifier,
                            $target_php_settings,
                            $descriptor->eg_address,
                        );

                        // Read call trace if triggers need it OR tracing is active
                        if ($needs_call_trace_for_triggers || ($trace_session?->isActive() ?? false)) {
                            $call_trace = $this->call_trace_reader
                                ->readCallTrace(
                                    $descriptor->pid,
                                    $descriptor->php_version,
                                    $descriptor->eg_address,
                                    $descriptor->sg_address,
                                    $get_trace_settings->depth,
                                    $trace_cache,
                                );
                        }

                        $variable_values = [];
                        if (count($var_specs) > 0) {
                            $variable_values = $this->variable_reader
                                ->readVariables(
                                    $var_specs,
                                    $process_specifier,
                                    $target_php_settings,
                                    $descriptor->eg_address,
                                );
                        }

                        if ($needs_rss) {
                            $rss_bytes = $this->rss_reader->read($descriptor->pid);
                        }

                        $cpu_percent = null;
                        if ($needs_cpu) {
                            $cpu_percent = $this->cpu_usage_reader->read($descriptor->pid);
                        }
                    } catch (\Throwable) {
                        $consecutive_failures++;
                        if ($consecutive_failures >= $max_consecutive_failures) {
                            Log::debug('watch worker: process seems dead', [
                                'pid' => $descriptor->pid,
                                'consecutive_failures' => $consecutive_failures,
                            ]);
                            break;
                        }
                        $previous_context = null;
                        $this->dynamicSleep($loop_start, ($trace_session?->isActive() ?? false) ? $trace_sleep_us : $poll_sleep_us);
                        continue;
                    }
                    $consecutive_failures = 0;

                    assert(isset($heap_stats));
                    assert(isset($variable_values));

                    $context = new WatchContext(
                        pid: $descriptor->pid,
                        heap_stats: $heap_stats,
                        call_trace: $call_trace,
                        timestamp: $now,
                        previous: $previous_context,
                        variable_values: $variable_values,
                        rss_bytes: $rss_bytes,
                        cpu_usage_percent: $cpu_percent,
                    );

                    // Record trace sample if continuous tracing is active
                    if ($trace_session?->isActive() && $call_trace !== null) {
                        $trace_session->recordSample($call_trace);
                    }

                    // Evaluate triggers with lifecycle tracking
                    foreach ($triggers as $trigger) {
                        $event = $trigger->evaluate($context);
                        $is_active = $event !== null;

                        if ($has_lifecycle) {
                            $phase = $state_tracker->update($trigger->name(), $is_active);

                            if ($phase === TriggerPhase::ENTER) {
                                // Start continuous trace on enter
                                if ($trace_session !== null && $event !== null) {
                                    $trace_session->start($descriptor->pid, $now);
                                    $this->protocol->sendTraceNotify(new WatchTraceNotifyMessage(
                                        pid: $descriptor->pid,
                                        started: true,
                                        trace_path: $trace_session->getCurrentPath(),
                                    ));
                                }
                            } elseif ($phase === TriggerPhase::EXIT) {
                                // Stop continuous trace on exit
                                if ($trace_session?->isActive()) {
                                    $path = $trace_session->getCurrentPath();
                                    $trace_session->stop();
                                    $this->protocol->sendTraceNotify(new WatchTraceNotifyMessage(
                                        pid: $descriptor->pid,
                                        started: false,
                                        trace_path: $path,
                                    ));
                                }
                                // Notify controller so it can execute on-exit actions
                                $this->protocol->sendTriggerExit(new WatchTriggerExitMessage(
                                    pid: $descriptor->pid,
                                    event: new \Reli\Inspector\Watch\TriggerEvent(
                                        trigger_name: $trigger->name(),
                                        description: 'condition cleared',
                                        timestamp: $now,
                                    ),
                                ));
                                $cooldown->recordClear($trigger->name());
                                continue;
                            }
                        }

                        if ($event === null) {
                            $cooldown->recordClear($trigger->name());
                            continue;
                        }
                        if (!$cooldown->canFire($trigger->name(), $now)) {
                            continue;
                        }
                        $cooldown->recordFire($trigger->name(), $now);
                        $this->protocol->sendTrigger(new WatchTriggerMessage(
                            pid: $descriptor->pid,
                            event: $event,
                            heap_stats: $heap_stats,
                            call_trace: $call_trace,
                            eg_address: $descriptor->eg_address,
                            cg_address: $descriptor->cg_address,
                            php_version: $descriptor->php_version,
                        ));
                    }

                    $previous_context = $context;

                    // Dynamic sleep: trace_interval when tracing, poll_interval otherwise
                    $this->dynamicSleep(
                        $loop_start,
                        ($trace_session?->isActive() ?? false) ? $trace_sleep_us : $poll_sleep_us,
                    );
                }
            } catch (\Throwable $e) {
                Log::debug('watch worker: exception', [
                    'pid' => $descriptor->pid,
                    'exception' => $e->getMessage(),
                ]);
            }

            // Stop trace session on detach
            if ($trace_session?->isActive()) {
                $path = $trace_session->getCurrentPath();
                $trace_session->stop();
                $this->protocol->sendTraceNotify(new WatchTraceNotifyMessage(
                    pid: $descriptor->pid,
                    started: false,
                    trace_path: $path,
                ));
            }

            if ($needs_cpu) {
                $this->cpu_usage_reader->clear($descriptor->pid);
            }
            $this->protocol->sendDetach(new WatchDetachMessage($descriptor->pid));
            Log::debug('watch worker: detached', ['pid' => $descriptor->pid]);
        }
    }

    /**
     * Sleep for the remaining interval, accounting for elapsed time.
     *
     * @param int $start hrtime(true) at loop start
     * @param int $interval_us target interval in microseconds
     */
    private function dynamicSleep(int $start, int $interval_us): void
    {
        $elapsed_us = (int)((\hrtime(true) - $start) / 1000);
        $wait = $interval_us - $elapsed_us;
        if ($wait > 0) {
            \usleep($wait);
        }
    }

    /**
     * @return list<TriggerInterface>
     */
    private function buildTriggers(\Reli\Inspector\Settings\WatchSettings\WatchSettings $settings): array
    {
        $triggers = [];

        if ($settings->memory_usage_bytes !== null) {
            $triggers[] = new MemoryUsageTrigger($settings->memory_usage_bytes);
        }
        if ($settings->memory_growth_rate !== null) {
            [$bytes, $seconds] = MemoryGrowthRateTrigger::parseRate($settings->memory_growth_rate);
            $triggers[] = new MemoryGrowthRateTrigger($bytes, $seconds);
        }
        if ($settings->memory_peak_watch) {
            $triggers[] = new MemoryPeakTrigger();
        }
        if ($settings->rss_usage_bytes !== null) {
            $triggers[] = new RssUsageTrigger($settings->rss_usage_bytes);
        }
        if ($settings->cpu_usage_percent !== null) {
            $triggers[] = new CpuUsageTrigger(
                enter_percent: $settings->cpu_usage_percent,
                exit_percent: $settings->cpu_usage_exit_percent ?? $settings->cpu_usage_percent,
                sustain_seconds: $settings->cpu_sustain_seconds,
            );
        }
        if ($settings->watch_function !== null) {
            $triggers[] = new FunctionDetectionTrigger($settings->watch_function);
        }
        if ($settings->trace_depth_limit !== null) {
            $triggers[] = new TraceDepthTrigger($settings->trace_depth_limit);
        }
        foreach ($settings->watch_var as $expr) {
            $triggers[] = new VariableValueTrigger($expr);
        }

        return $triggers;
    }
}
