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

namespace Reli\Command\Inspector;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Reli\Inspector\Daemon\Dispatcher\DispatchTable;
use Reli\Inspector\Daemon\Dispatcher\WorkerPool;
use Reli\Inspector\Daemon\Reader\Context\PhpReaderContextCreator;
use Reli\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;
use Reli\Inspector\Daemon\Searcher\Context\PhpSearcherContextCreator;
use Reli\Inspector\Output\TraceOutput\TraceOutputFactory;
use Reli\Inspector\RetryingLoopProvider;
use Reli\Inspector\Settings\DaemonSettings\DaemonSettingsFromConsoleInput;
use Reli\Inspector\Settings\GetTraceSettings\GetTraceSettingsFromConsoleInput;
use Reli\Inspector\Settings\OutputSettings\OutputSettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetProcessSettings\TargetProcessSettingsFromConsoleInput;
use Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;
use Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettingsFromConsoleInput;
use Reli\Inspector\Settings\WatchSettings\WatchSettingsFromConsoleInput;
use Reli\Inspector\TargetProcess\TargetProcessResolver;
use Reli\Inspector\TraceLoopProvider;
use Reli\Lib\Console\EchoBackCanceller;
use Reli\Lib\Log\Log;
use Revolt\EventLoop;
use Reli\Inspector\Watch\Action\ActionInterface;
use Reli\Inspector\Watch\Action\ExecAction;
use Reli\Inspector\Watch\Action\LogAction;
use Reli\Inspector\Watch\Action\TraceAction;
use Reli\Inspector\Watch\CooldownManager;
use Reli\Inspector\Watch\DiskUsageTracker;
use Reli\Inspector\Watch\HeapStats;
use Reli\Inspector\Watch\HeapStatsReader;
use Reli\Inspector\Watch\Trigger\MemoryGrowthRateTrigger;
use Reli\Inspector\Watch\Trigger\MemoryLimitTrigger;
use Reli\Inspector\Watch\Trigger\MemoryPeakTrigger;
use Reli\Inspector\Watch\Trigger\ExceptionDetectionTrigger;
use Reli\Inspector\Watch\Trigger\FunctionDetectionTrigger;
use Reli\Inspector\Watch\Trigger\TraceDepthTrigger;
use Reli\Inspector\Watch\Trigger\TriggerInterface;
use Reli\Inspector\Watch\TriggerEvent;
use Reli\Inspector\Watch\WatchContext;
use Reli\Lib\Elf\Process\BinaryAnalysisCache;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader;
use Reli\Lib\PhpProcessReader\CallTraceReader\TraceCache;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpVersionDetector;
use Reli\Lib\Process\ProcessStopper\ProcessStopper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Amp\async;
use function Amp\Future\await;
use function fread;
use function Reli\Lib\Defer\defer;

use const STDIN;

final class WatchCommand extends Command
{
    public function __construct(
        private PhpGlobalsFinder $php_globals_finder,
        private PhpVersionDetector $php_version_detector,
        private HeapStatsReader $heap_stats_reader,
        private CallTraceReader $call_trace_reader,
        private TraceLoopProvider $loop_provider,
        private TargetProcessResolver $target_process_resolver,
        private RetryingLoopProvider $retrying_loop_provider,
        private ProcessStopper $process_stopper,
        private BinaryAnalysisCache $binary_analysis_cache,
        private WatchSettingsFromConsoleInput $watch_settings_from_console_input,
        private TargetPhpSettingsFromConsoleInput $target_php_settings_from_console_input,
        private TargetProcessSettingsFromConsoleInput $target_process_settings_from_console_input,
        private GetTraceSettingsFromConsoleInput $get_trace_settings_from_console_input,
        private TraceLoopSettingsFromConsoleInput $trace_loop_settings_from_console_input,
        private OutputSettingsFromConsoleInput $output_settings_from_console_input,
        private DaemonSettingsFromConsoleInput $daemon_settings_from_console_input,
        private TraceOutputFactory $trace_output_factory,
        private PhpSearcherContextCreator $php_searcher_context_creator,
        private PhpReaderContextCreator $php_reader_context_creator,
    ) {
        parent::__construct();
    }

    #[\Override]
    public function configure(): void
    {
        $this->setName('inspector:watch')
            ->setDescription(
                'monitor a PHP process and trigger actions when configurable conditions are met'
            )
        ;
        $this->watch_settings_from_console_input->setOptions($this);
        $this->target_process_settings_from_console_input->setOptions($this);
        $this->target_php_settings_from_console_input->setOptions($this);
        $this->get_trace_settings_from_console_input->setOptions($this);
        $this->trace_loop_settings_from_console_input->setOptions($this);
        $this->output_settings_from_console_input->setOptions($this);
        $this->daemon_settings_from_console_input->setOptions($this);
        $this->addOption('no-cache', null, InputOption::VALUE_NONE, 'disable the binary analysis cache');
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $no_cache = (bool)$input->getOption('no-cache');
        if ($no_cache) {
            $this->binary_analysis_cache->disable();
        }

        $watch_settings = $this->watch_settings_from_console_input->createSettings($input);
        $get_trace_settings = $this->get_trace_settings_from_console_input->createSettings($input);
        $output_settings = $this->output_settings_from_console_input->createSettings($input);

        // Daemon mode: --target-regex is specified
        $target_regex = $input->getOption('target-regex');
        if ($target_regex !== null) {
            return $this->executeDaemonMode($input, $output, $watch_settings, $get_trace_settings, $no_cache);
        }

        // Single-process mode
        $target_php_settings = $this->target_php_settings_from_console_input->createSettings($input);
        $target_process_settings = $this->target_process_settings_from_console_input->createSettings($input);

        $process_specifier = $this->target_process_resolver->resolve($target_process_settings);

        $target_php_settings = $this->php_version_detector->decidePhpVersion(
            $process_specifier,
            $target_php_settings,
        );

        $eg_address = $this->retrying_loop_provider->do(
            try: fn () => $this->php_globals_finder->findExecutorGlobals(
                $process_specifier,
                $target_php_settings,
            ),
            retry_on: [\Throwable::class],
            max_retry: 10,
            interval_on_retry_ns: 1000 * 1000 * 10,
        );

        $sg_address = $this->php_globals_finder->findSAPIGlobals(
            $process_specifier,
            $target_php_settings,
        );

        // Build triggers
        $triggers = $this->buildTriggers($watch_settings);
        if (count($triggers) === 0) {
            $output->writeln('<error>No triggers specified. Use --memory-limit, --memory-growth-rate, --watch-function, etc.</error>');
            return 1;
        }

        // Check if any trigger requires call trace
        $needs_call_trace = false;
        foreach ($triggers as $trigger) {
            if ($trigger->requiresCallTrace()) {
                $needs_call_trace = true;
                break;
            }
        }

        // Build actions
        $trace_output = $this->trace_output_factory->fromSettingsAndConsoleOutput(
            $output,
            $output_settings,
        );
        $actions = $this->buildActions($watch_settings, $trace_output, $target_php_settings->php_version, $eg_address, $sg_address, $get_trace_settings->depth);

        // Build rate limiters
        $cooldown = new CooldownManager(
            base_cooldown_seconds: (float)$watch_settings->cooldown_seconds,
            backoff_multiplier: $watch_settings->backoff_multiplier,
            backoff_max_seconds: (float)$watch_settings->backoff_max_seconds,
            max_triggers_per_hour: $watch_settings->max_triggers_per_hour,
            max_triggers: $watch_settings->max_triggers,
        );
        $disk_tracker = new DiskUsageTracker($watch_settings->max_dump_size_bytes);

        $php_version = $target_php_settings->php_version;
        $depth = $get_trace_settings->depth;
        $stop_process = (bool)($input->getOption('stop-process') ?? false);
        $quiet = $watch_settings->quiet;

        $previous_context = null;
        $poll_count = 0;

        $loop_settings = new TraceLoopSettings(
            sleep_nano_seconds: $watch_settings->poll_interval_ms * 1000 * 1000,
            cancel_key: 'q',
            max_retries: 10,
            stop_process: false,
        );

        if (!$quiet) {
            $output->writeln(sprintf(
                '<info>[watch] Monitoring PID=%d | triggers=%s | actions=%s</info>',
                $process_specifier->pid,
                implode(',', array_map(fn (TriggerInterface $t) => $t->name(), $triggers)),
                implode(',', array_map(fn (ActionInterface $a) => $a->name(), $actions)),
            ));
        }

        $this->loop_provider->getMainLoop(
            function () use (
                $process_specifier,
                $target_php_settings,
                $eg_address,
                $sg_address,
                $php_version,
                $depth,
                $stop_process,
                $needs_call_trace,
                $triggers,
                $actions,
                $cooldown,
                $disk_tracker,
                $quiet,
                $output,
                &$previous_context,
                &$poll_count,
            ): bool {
                $poll_count++;
                $now = microtime(true);

                // Stop process if needed for reading
                if ($stop_process && $this->process_stopper->stop($process_specifier->pid)) {
                    defer($_, fn () => $this->process_stopper->resume($process_specifier->pid));
                }

                // Read heap stats (lightweight, < 1ms)
                $heap_stats = $this->heap_stats_reader->read(
                    $process_specifier,
                    $target_php_settings,
                    $eg_address,
                );

                // Read call trace if needed
                $call_trace = null;
                if ($needs_call_trace) {
                    $call_trace = $this->call_trace_reader->readCallTrace(
                        $process_specifier->pid,
                        $php_version,
                        $eg_address,
                        $sg_address,
                        $depth,
                        new TraceCache(),
                    );
                }

                $context = new WatchContext(
                    pid: $process_specifier->pid,
                    heap_stats: $heap_stats,
                    call_trace: $call_trace,
                    has_exception: null,
                    timestamp: $now,
                    previous: $previous_context,
                );

                // Evaluate triggers
                foreach ($triggers as $trigger) {
                    $event = $trigger->evaluate($context);
                    if ($event === null) {
                        $cooldown->recordClear($trigger->name());
                        continue;
                    }

                    if (!$cooldown->canFire($trigger->name(), $now)) {
                        if (!$quiet) {
                            $reason = $cooldown->getSkipReason($trigger->name(), $now);
                            $output->writeln(sprintf(
                                '<comment>[SKIPPED] PID=%d | trigger=%s | reason=%s</comment>',
                                $process_specifier->pid,
                                $trigger->name(),
                                $reason ?? 'cooldown',
                            ));
                        }
                        continue;
                    }

                    // Fire!
                    $cooldown->recordFire($trigger->name(), $now);

                    if (!$quiet) {
                        $output->writeln(sprintf(
                            '<info>[TRIGGERED] PID=%d | trigger=%s | %s</info>',
                            $process_specifier->pid,
                            $event->trigger_name,
                            $event->description,
                        ));
                    }

                    foreach ($actions as $action) {
                        $action->execute($event, $process_specifier, $context);
                    }
                }

                // Update status line (single process mode)
                if (!$quiet && $poll_count % 10 === 0) {
                    $output->write(sprintf(
                        "\r<info>[watching]</info> PID=%d | mem=%s/%s | polls=%d | triggers=%d | disk=%s",
                        $process_specifier->pid,
                        HeapStats::humanReadableBytes($heap_stats->size),
                        HeapStats::humanReadableBytes($heap_stats->limit > 0 ? $heap_stats->limit : $heap_stats->real_size),
                        $poll_count,
                        $cooldown->getTotalFires(),
                        HeapStats::humanReadableBytes($disk_tracker->getTotalBytes()),
                    ));
                }

                $previous_context = $context;

                // Stop if max triggers reached
                return !$cooldown->hasReachedMaxTriggers();
            },
            $loop_settings,
        )->invoke();

        if (!$quiet) {
            $output->writeln('');
            $output->writeln('<info>[watch] Monitoring stopped.</info>');
        }

        return 0;
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

        // Tier 2 triggers
        if ($settings->watch_function !== null) {
            $triggers[] = new FunctionDetectionTrigger($settings->watch_function);
        }

        if ($settings->trace_depth_limit !== null) {
            $triggers[] = new TraceDepthTrigger($settings->trace_depth_limit);
        }

        // Tier 3 triggers
        if ($settings->on_exception) {
            $triggers[] = new ExceptionDetectionTrigger();
        }

        return $triggers;
    }

    /**
     * @return list<ActionInterface>
     */
    private function buildActions(
        \Reli\Inspector\Settings\WatchSettings\WatchSettings $settings,
        \Reli\Inspector\Output\TraceOutput\TraceOutput $trace_output,
        string $php_version,
        int $eg_address,
        int $sg_address,
        int $depth,
    ): array {
        $actions = [];

        foreach ($settings->actions as $action_name) {
            switch ($action_name) {
                case 'trace':
                    $actions[] = new TraceAction(
                        $this->call_trace_reader,
                        $trace_output,
                        $php_version,
                        $eg_address,
                        $sg_address,
                        $depth,
                    );
                    break;
                case 'log':
                    $actions[] = $settings->log_file !== null
                        ? LogAction::toFile($settings->log_file)
                        : new LogAction();
                    break;
                case 'memory-dump':
                    // Phase 2: MemoryDumpAction
                    break;
                case 'exec':
                    if ($settings->action_exec_command !== null) {
                        $actions[] = new ExecAction($settings->action_exec_command);
                    }
                    break;
            }
        }

        // If no actions were built (e.g., only memory-dump requested but not yet implemented),
        // fall back to trace
        if (count($actions) === 0) {
            $actions[] = new TraceAction(
                $this->call_trace_reader,
                $trace_output,
                $php_version,
                $eg_address,
                $sg_address,
                $depth,
            );
        }

        return $actions;
    }

    /**
     * Daemon mode: monitor multiple processes matching --target-regex.
     *
     * Reuses existing daemon infrastructure (PhpSearcherContextCreator + PhpReaderContextCreator)
     * but evaluates triggers on received traces in the main thread instead of outputting them.
     */
    private function executeDaemonMode(
        InputInterface $input,
        OutputInterface $output,
        \Reli\Inspector\Settings\WatchSettings\WatchSettings $watch_settings,
        \Reli\Inspector\Settings\GetTraceSettings\GetTraceSettings $get_trace_settings,
        bool $no_cache,
    ): int {
        $daemon_settings = $this->daemon_settings_from_console_input->createSettings($input);
        $target_php_settings = $this->target_php_settings_from_console_input->createSettings($input);
        $loop_settings = $this->trace_loop_settings_from_console_input->createSettings($input);

        // Build triggers (daemon mode: only Tier 1 triggers use heap stats from main thread,
        // Tier 2 triggers evaluate on traces received from workers)
        $triggers = $this->buildTriggers($watch_settings);
        if (count($triggers) === 0) {
            $output->writeln('<error>No triggers specified.</error>');
            return 1;
        }

        // Build actions (daemon mode: log action is most practical, trace output goes to stdout)
        $output_settings = $this->output_settings_from_console_input->createSettings($input);
        $trace_output = $this->trace_output_factory->fromSettingsAndConsoleOutput($output, $output_settings);
        // In daemon mode, actions don't need eg/sg addresses (trace comes from worker)
        $actions = $this->buildDaemonActions($watch_settings, $trace_output);

        $cooldown = new CooldownManager(
            base_cooldown_seconds: (float)$watch_settings->cooldown_seconds,
            backoff_multiplier: $watch_settings->backoff_multiplier,
            backoff_max_seconds: (float)$watch_settings->backoff_max_seconds,
            max_triggers_per_hour: $watch_settings->max_triggers_per_hour,
            max_triggers: $watch_settings->max_triggers,
        );

        $quiet = $watch_settings->quiet;

        // Start process searcher
        $searcher_context = $this->php_searcher_context_creator->create();
        $searcher_context->start();
        $my_pid = getmypid();
        if ($my_pid === false) {
            throw new \RuntimeException('Failed to get current process ID');
        }
        $searcher_context->sendTarget(
            $daemon_settings->target_regex,
            $target_php_settings,
            $my_pid,
            $no_cache,
        );

        // Create worker pool
        $worker_pool = WorkerPool::create(
            $this->php_reader_context_creator,
            $daemon_settings->threads,
            $loop_settings,
            $get_trace_settings,
        );

        $dispatch_table = new DispatchTable($worker_pool);

        $_echo_back_canceler = new EchoBackCanceller();
        $cancellation = new DeferredCancellation();

        EventLoop::onReadable(
            STDIN,
            function (string $watcher_id, $stream) use ($cancellation) {
                $key = fread($stream, 1);
                if ($key === 'q') {
                    EventLoop::cancel($watcher_id);
                    $cancellation->cancel();
                }
            }
        );

        if (!$quiet) {
            $output->writeln(sprintf(
                '<info>[watch-daemon] Monitoring processes matching "%s" | triggers=%s</info>',
                $daemon_settings->target_regex,
                implode(',', array_map(fn (TriggerInterface $t) => $t->name(), $triggers)),
            ));
        }

        /** @var array<int, WatchContext> $per_process_context */
        $per_process_context = [];

        $futures = [];

        // Searcher future: continuously discovers processes
        $futures[] = async(function () use ($searcher_context, $dispatch_table, $output, $quiet) {
            while (1) {
                $update_target_message = $searcher_context->receivePidList();
                $dispatch_table->updateTargets($update_target_message->target_process_list);
                Log::debug('watch daemon targets updated');
            }
        });

        // Reader futures: receive traces from workers and evaluate triggers
        foreach ($worker_pool->getWorkers() as $reader) {
            $futures[] = async(
                function () use (
                    $reader,
                    $dispatch_table,
                    $triggers,
                    $actions,
                    $cooldown,
                    $output,
                    $quiet,
                    &$per_process_context,
                ) {
                    while (1) {
                        $result = $reader->receiveTraceOrDetachWorker();
                        if ($result instanceof TraceMessage) {
                            // We don't have heap stats in daemon mode (workers only send traces),
                            // so create a minimal WatchContext with the trace
                            $now = microtime(true);
                            // Use a placeholder HeapStats — daemon mode primarily uses Tier 2 triggers
                            $heap_stats = new HeapStats(0, 0, 0, 0);
                            $pid = 0; // PID is not directly available from TraceMessage

                            $context = new WatchContext(
                                pid: $pid,
                                heap_stats: $heap_stats,
                                call_trace: $result->trace,
                                has_exception: null,
                                timestamp: $now,
                                previous: null,
                            );

                            foreach ($triggers as $trigger) {
                                if (!$trigger->requiresCallTrace()) {
                                    continue; // Skip Tier 1 triggers in daemon mode (no heap stats)
                                }
                                $event = $trigger->evaluate($context);
                                if ($event === null) {
                                    $cooldown->recordClear($trigger->name());
                                    continue;
                                }
                                if (!$cooldown->canFire($trigger->name(), $now)) {
                                    if (!$quiet) {
                                        $reason = $cooldown->getSkipReason($trigger->name(), $now);
                                        $output->writeln(sprintf(
                                            '<comment>[SKIPPED] trigger=%s | reason=%s</comment>',
                                            $trigger->name(),
                                            $reason ?? 'cooldown',
                                        ));
                                    }
                                    continue;
                                }
                                $cooldown->recordFire($trigger->name(), $now);
                                if (!$quiet) {
                                    $output->writeln(sprintf(
                                        '<info>[TRIGGERED] trigger=%s | %s</info>',
                                        $event->trigger_name,
                                        $event->description,
                                    ));
                                }
                                foreach ($actions as $action) {
                                    $action->execute(
                                        $event,
                                        new \Reli\Lib\Process\ProcessSpecifier($pid),
                                        $context,
                                    );
                                }
                            }
                        } else {
                            Log::debug('watch daemon worker detached', [$result]);
                            $dispatch_table->releaseOne($result->pid);
                        }
                    }
                }
            );
        }

        try {
            await($futures, $cancellation->getCancellation());
        } catch (CancelledException) {
            Log::debug('watch daemon cancelled');
        }

        if (!$quiet) {
            $output->writeln('');
            $output->writeln('<info>[watch-daemon] Monitoring stopped.</info>');
        }

        return 0;
    }

    /**
     * Build actions for daemon mode (no per-process EG/SG addresses available).
     *
     * @return list<ActionInterface>
     */
    private function buildDaemonActions(
        \Reli\Inspector\Settings\WatchSettings\WatchSettings $settings,
        \Reli\Inspector\Output\TraceOutput\TraceOutput $trace_output,
    ): array {
        $actions = [];

        foreach ($settings->actions as $action_name) {
            switch ($action_name) {
                case 'trace':
                    // In daemon mode, trace output uses the trace from worker directly
                    $actions[] = new \Reli\Inspector\Watch\Action\DaemonTraceAction($trace_output);
                    break;
                case 'log':
                    $actions[] = $settings->log_file !== null
                        ? LogAction::toFile($settings->log_file)
                        : new LogAction();
                    break;
                case 'exec':
                    if ($settings->action_exec_command !== null) {
                        $actions[] = new ExecAction($settings->action_exec_command);
                    }
                    break;
            }
        }

        if (count($actions) === 0) {
            $actions[] = $settings->log_file !== null
                ? LogAction::toFile($settings->log_file)
                : new LogAction();
        }

        return $actions;
    }
}
