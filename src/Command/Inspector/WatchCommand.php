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
use Reli\Inspector\Daemon\Searcher\Context\PhpSearcherContextCreator;
use Reli\Inspector\Watch\Daemon\Context\PhpWatchContextCreator;
use Reli\Inspector\Watch\Daemon\Controller\PhpWatchControllerInterface;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchDetachMessage;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchTraceNotifyMessage;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchTriggerExitMessage;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchTriggerMessage;
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
use Reli\Lib\Directory\AppDirectory;
use Reli\Lib\Log\Log;
use Revolt\EventLoop;
use Reli\Inspector\Watch\Action\ActionInterface;
use Reli\Inspector\Watch\ActionFactory;
use Reli\Inspector\Watch\CooldownManager;
use Reli\Inspector\Watch\CpuUsageReader;
use Reli\Inspector\Watch\DiskUsageTracker;
use Reli\Inspector\Watch\HeapStats;
use Reli\Inspector\Watch\HeapStatsReader;
use Reli\Inspector\Watch\RssReader;
use Reli\Inspector\Watch\TraceSession;
use Reli\Inspector\Watch\TriggerFactory;
use Reli\Inspector\Watch\TriggerPhase;
use Reli\Inspector\Watch\TriggerStateTracker;
use Reli\Inspector\Watch\VariableReader;
use Reli\Inspector\Watch\VariableSpec;
use Reli\Inspector\Watch\Trigger\CpuUsageTrigger;
use Reli\Inspector\Watch\Trigger\RssUsageTrigger;
use Reli\Inspector\Watch\Trigger\TriggerInterface;
use Reli\Inspector\Watch\Trigger\VariableValueTrigger;
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
        private RssReader $rss_reader,
        private CpuUsageReader $cpu_usage_reader,
        private VariableReader $variable_reader,
        private CallTraceReader $call_trace_reader,
        private TriggerFactory $trigger_factory,
        private ActionFactory $action_factory,
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
        private PhpWatchContextCreator $php_watch_context_creator,
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
        if ($watch_settings->memory_limit !== null) {
            ini_set('memory_limit', $watch_settings->memory_limit);
        }
        $get_trace_settings = $this->get_trace_settings_from_console_input->createSettings($input);
        $output_settings = $this->output_settings_from_console_input->createSettings($input);

        // Daemon mode: --target-regex is specified
        $target_regex = $input->getOption('target-regex');
        if ($target_regex !== null) {
            return $this->executeDaemonMode($input, $output, $watch_settings, $get_trace_settings, $no_cache);
        }

        // Single-process mode: check triggers early (before PID resolution)
        $triggers = $this->trigger_factory->build($watch_settings);
        if (count($triggers) === 0) {
            $output->writeln(
                '<error>No triggers specified.'
                . ' Use --memory-usage,'
                . ' --memory-growth-rate,'
                . ' --watch-function, etc.</error>'
            );
            return 1;
        }

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

        $cg_address = $this->php_globals_finder->findCompilerGlobals(
            $process_specifier,
            $target_php_settings,
        );

        // Check what each trigger needs
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

        // Determine if on-enter/on-exit lifecycle is active
        $has_lifecycle = count($watch_settings->on_enter_actions) > 0
            || count($watch_settings->on_exit_actions) > 0;

        // Build actions
        $trace_output = $this->trace_output_factory->fromSettingsAndConsoleOutput(
            $output,
            $output_settings,
        );
        // Ensure output directory exists
        AppDirectory::ensureDirectoryExists($watch_settings->action_output_dir);

        // Build rate limiters (need disk_tracker before actions)
        $disk_tracker = new DiskUsageTracker(
            $watch_settings->max_dump_size_bytes,
            $watch_settings->action_output_dir,
        );

        $actions = $this->action_factory->buildActions(
            $watch_settings,
            $trace_output,
            $target_php_settings->php_version,
            $eg_address,
            $sg_address,
            $cg_address,
            $get_trace_settings->depth,
            $target_php_settings,
            $disk_tracker,
        );

        // Build trace session for continuous tracing
        $trace_session = new TraceSession(
            $watch_settings->action_output_dir,
            $watch_settings->trace_interval_ms * 1000,
        );

        // Build lifecycle actions (on-enter / on-exit)
        $on_enter_actions = $this->action_factory->buildLifecycleActions(
            $watch_settings->on_enter_actions,
            $watch_settings,
            $trace_output,
            $target_php_settings->php_version,
            $eg_address,
            $sg_address,
            $cg_address,
            $get_trace_settings->depth,
            $target_php_settings,
            $disk_tracker,
            $trace_session,
        );
        $on_exit_actions = $this->action_factory->buildLifecycleActions(
            $watch_settings->on_exit_actions,
            $watch_settings,
            $trace_output,
            $target_php_settings->php_version,
            $eg_address,
            $sg_address,
            $cg_address,
            $get_trace_settings->depth,
            $target_php_settings,
            $disk_tracker,
            $trace_session,
        );

        // Build cooldown
        // CooldownManager handles per-trigger cooldown/backoff only.
        // Global max-triggers is managed via $action_count above.
        $cooldown = new CooldownManager(
            base_cooldown_seconds: (float)$watch_settings->cooldown_seconds,
            backoff_multiplier: $watch_settings->backoff_multiplier,
            backoff_max_seconds: (float)$watch_settings->backoff_max_seconds,
            max_triggers_per_hour: $watch_settings->max_triggers_per_hour,
            max_triggers: 0,
        );

        $state_tracker = new TriggerStateTracker();

        $php_version = $target_php_settings->php_version;
        $depth = $get_trace_settings->depth;
        $stop_process = (bool)($input->getOption('stop-process') ?? false);
        $quiet = $watch_settings->quiet;

        $previous_context = null;
        $poll_count = 0;
        $action_count = 0;

        // Use sleep=0 in middleware; timing is managed inside the closure
        // to support dynamic interval (poll_interval vs trace_interval).
        $loop_settings = new TraceLoopSettings(
            sleep_nano_seconds: 0,
            cancel_key: 'q',
            max_retries: 0, // no retry — we handle errors per poll
            stop_process: false,
        );

        $poll_interval_ns = $watch_settings->poll_interval_ms * 1000 * 1000;
        $trace_interval_ns = $watch_settings->trace_interval_ms * 1000 * 1000;

        if (!$quiet) {
            $action_names = array_map(fn (ActionInterface $a) => $a->name(), $actions);
            $enter_names = array_map(fn (ActionInterface $a) => $a->name(), $on_enter_actions);
            $exit_names = array_map(fn (ActionInterface $a) => $a->name(), $on_exit_actions);
            $all_action_desc = implode(',', $action_names);
            if (count($enter_names) > 0) {
                $all_action_desc .= ' on-enter=' . implode(',', $enter_names);
            }
            if (count($exit_names) > 0) {
                $all_action_desc .= ' on-exit=' . implode(',', $exit_names);
            }
            $output->writeln(sprintf(
                '<info>[watch] Monitoring PID=%d | triggers=%s | %s</info>',
                $process_specifier->pid,
                implode(',', array_map(fn (TriggerInterface $t) => $t->name(), $triggers)),
                $all_action_desc,
            ));
        }

        // Prime the CPU reader with a first sample (always returns null on first call)
        if ($needs_cpu) {
            $this->cpu_usage_reader->read($process_specifier->pid);
        }

        $this->loop_provider->getMainLoop(
            function () use (
                $process_specifier,
                $target_php_settings,
                $eg_address,
                $sg_address,
                $cg_address,
                $php_version,
                $depth,
                $stop_process,
                $needs_call_trace,
                $needs_rss,
                $needs_cpu,
                $has_lifecycle,
                $var_specs,
                $triggers,
                $actions,
                $on_enter_actions,
                $on_exit_actions,
                $cooldown,
                $state_tracker,
                $trace_session,
                $disk_tracker,
                $quiet,
                $watch_settings,
                $output,
                $poll_interval_ns,
                $trace_interval_ns,
                &$previous_context,
                &$poll_count,
                &$action_count,
            ): bool {
                $loop_start = \hrtime(true);
                $poll_count++;
                $now = microtime(true);

                // Stop early if max action executions already reached
                if (
                    $watch_settings->max_triggers > 0
                    && $action_count >= $watch_settings->max_triggers
                ) {
                    $trace_session->stop();
                    return false;
                }

                // Read process state. If the target is between
                // requests (e.g., fpm accept() wait) or temporarily
                // unreadable, skip this poll and try again next cycle.
                try {
                    if (
                        $stop_process
                        && $this->process_stopper->stop(
                            $process_specifier->pid,
                        )
                    ) {
                        defer(
                            $_,
                            fn () => $this->process_stopper->resume(
                                $process_specifier->pid,
                            ),
                        );
                    }

                    $heap_stats = $this->heap_stats_reader->read(
                        $process_specifier,
                        $target_php_settings,
                        $eg_address,
                    );

                    // Read call trace if triggers need it OR tracing is active
                    $call_trace = null;
                    if ($needs_call_trace || $trace_session->isActive()) {
                        $call_trace = $this->call_trace_reader
                            ->readCallTrace(
                                $process_specifier->pid,
                                $php_version,
                                $eg_address,
                                $sg_address,
                                $depth,
                                new TraceCache(),
                            );
                    }

                    $variable_values = [];
                    if (count($var_specs) > 0) {
                        $variable_values = $this->variable_reader
                            ->readVariables(
                                $var_specs,
                                $process_specifier,
                                $target_php_settings,
                                $eg_address,
                                $cg_address,
                            );
                    }

                    $rss_bytes = null;
                    if ($needs_rss) {
                        $rss_bytes = $this->rss_reader->read($process_specifier->pid);
                    }

                    $cpu_percent = null;
                    if ($needs_cpu) {
                        $cpu_percent = $this->cpu_usage_reader->read($process_specifier->pid);
                    }
                } catch (\Throwable) {
                    // Target may be between requests or temporarily
                    // unreadable. Skip this poll, try next cycle.
                    $previous_context = null;
                    $sleep_ns = $trace_session->isActive() ? $trace_interval_ns : $poll_interval_ns;
                    $this->dynamicSleep($loop_start, $sleep_ns);
                    return true;
                }

                $context = new WatchContext(
                    pid: $process_specifier->pid,
                    heap_stats: $heap_stats,
                    call_trace: $call_trace,
                    timestamp: $now,
                    previous: $previous_context,
                    variable_values: $variable_values,
                    rss_bytes: $rss_bytes,
                    cpu_usage_percent: $cpu_percent,
                );

                // Record trace sample if continuous tracing is active
                if ($trace_session->isActive() && $call_trace !== null) {
                    $trace_session->recordSample($call_trace);
                }

                // Evaluate triggers with lifecycle tracking
                /** @var list<TriggerEvent> $fired_events */
                $fired_events = [];
                foreach ($triggers as $trigger) {
                    $event = $trigger->evaluate($context);
                    $is_active = $event !== null;

                    if ($has_lifecycle) {
                        $phase = $state_tracker->update($trigger->name(), $is_active);

                        if ($phase === TriggerPhase::ENTER && $event !== null) {
                            if (!$quiet) {
                                $output->writeln(sprintf(
                                    '<info>[ENTER] PID=%d | trigger=%s | %s</info>',
                                    $process_specifier->pid,
                                    $event->trigger_name,
                                    $event->description,
                                ));
                            }
                            foreach ($on_enter_actions as $action) {
                                $action->execute($event, $process_specifier, $context);
                            }
                        } elseif ($phase === TriggerPhase::EXIT) {
                            $exit_event = new TriggerEvent(
                                trigger_name: $trigger->name(),
                                description: 'condition cleared',
                                timestamp: $now,
                            );
                            if (!$quiet) {
                                $output->writeln(sprintf(
                                    '<info>[EXIT] PID=%d | trigger=%s | condition cleared</info>',
                                    $process_specifier->pid,
                                    $trigger->name(),
                                ));
                            }
                            foreach ($on_exit_actions as $action) {
                                $action->execute($exit_event, $process_specifier, $context);
                            }
                            $cooldown->recordClear($trigger->name());
                            continue;
                        }
                    }

                    if ($event === null) {
                        $cooldown->recordClear($trigger->name());
                        continue;
                    }

                    // Regular action path (fires while active, with cooldown)
                    if (count($actions) > 0) {
                        if (!$cooldown->canFire($trigger->name(), $now)) {
                            if (!$quiet && !$has_lifecycle) {
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

                        $cooldown->recordFire($trigger->name(), $now);
                        $fired_events[] = $event;

                        if (!$quiet && !$has_lifecycle) {
                            $output->writeln(sprintf(
                                '<info>[TRIGGERED] PID=%d | trigger=%s | %s</info>',
                                $process_specifier->pid,
                                $event->trigger_name,
                                $event->description,
                            ));
                        }
                    }
                }

                // Execute regular actions once per poll (not per trigger)
                if (count($fired_events) > 0) {
                    $action_count++;
                    $merged_event = count($fired_events) === 1
                        ? $fired_events[0]
                        : new TriggerEvent(
                            trigger_name: implode(
                                '+',
                                array_map(
                                    fn (TriggerEvent $e) => $e->trigger_name,
                                    $fired_events,
                                ),
                            ),
                            description: implode(
                                '; ',
                                array_map(
                                    fn (TriggerEvent $e) => $e->description,
                                    $fired_events,
                                ),
                            ),
                            timestamp: $now,
                        );

                    foreach ($actions as $action) {
                        $action->execute($merged_event, $process_specifier, $context);
                    }
                }

                // Update status line (single process mode)
                if (!$quiet && $poll_count % 10 === 0) {
                    $tracing_indicator = $trace_session->isActive() ? ' | TRACING' : '';
                    $cpu_indicator = $cpu_percent !== null ? sprintf(' | cpu=%.1f%%', $cpu_percent) : '';
                    $output->write(sprintf(
                        "\r<info>[watching]</info> PID=%d | mem=%s/%s%s | polls=%d | triggers=%d | disk=%s%s",
                        $process_specifier->pid,
                        HeapStats::humanReadableBytes($heap_stats->size),
                        HeapStats::humanReadableBytes(
                            $heap_stats->limit > 0
                                ? $heap_stats->limit
                                : $heap_stats->real_size
                        ),
                        $cpu_indicator,
                        $poll_count,
                        $cooldown->getTotalFires(),
                        HeapStats::humanReadableBytes($disk_tracker->getTotalBytes()),
                        $tracing_indicator,
                    ));
                }

                $previous_context = $context;

                // Dynamic sleep: use trace_interval when tracing, poll_interval otherwise
                $interval_ns = $trace_session->isActive() ? $trace_interval_ns : $poll_interval_ns;
                $this->dynamicSleep($loop_start, $interval_ns);

                return true;
            },
            $loop_settings,
        )->invoke();

        // Ensure trace session is finalized on exit
        $trace_session->stop();

        if (!$quiet) {
            $output->writeln('');
            $output->writeln('<info>[watch] Monitoring stopped.</info>');
        }

        return 0;
    }

    /**
     * Sleep for the remaining interval, accounting for elapsed execution time.
     */
    private function dynamicSleep(int $start_hrtime_ns, int $interval_ns): void
    {
        $elapsed = \hrtime(true) - $start_hrtime_ns;
        $wait = $interval_ns - $elapsed;
        if ($wait > 0) {
            \time_nanosleep(0, (int)$wait);
        }
    }

    /**
     * Daemon mode: monitor multiple processes matching --target-regex.
     *
     * Uses PhpWatchContextCreator (WatchTriggerMessage-based workers) that evaluate
     * triggers inside the worker process and send only trigger events back.
     * All trigger tiers (including Tier 1 memory-usage) work in daemon mode.
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

        $triggers = $this->trigger_factory->build($watch_settings);
        if (count($triggers) === 0) {
            $output->writeln('<error>No triggers specified.</error>');
            return 1;
        }

        // Build actions for daemon mode
        AppDirectory::ensureDirectoryExists($watch_settings->action_output_dir);
        $output_settings = $this->output_settings_from_console_input->createSettings($input);
        $trace_output = $this->trace_output_factory->fromSettingsAndConsoleOutput($output, $output_settings);
        $disk_tracker = new DiskUsageTracker(
            $watch_settings->max_dump_size_bytes,
            $watch_settings->action_output_dir,
        );
        $actions = $this->action_factory->buildDaemonActions(
            $watch_settings,
            $trace_output,
            $disk_tracker,
        );

        // Build daemon lifecycle actions for controller-side one-shot actions (log, exec).
        // trace/stop-trace are handled worker-side via TraceSession.
        $on_enter_actions = $this->action_factory->buildDaemonLifecycleActions(
            $watch_settings->on_enter_actions,
            $watch_settings,
            $trace_output,
        );
        $on_exit_actions = $this->action_factory->buildDaemonLifecycleActions(
            $watch_settings->on_exit_actions,
            $watch_settings,
            $trace_output,
        );
        $has_daemon_lifecycle = count($on_enter_actions) > 0 || count($on_exit_actions) > 0;
        $daemon_state_tracker = new TriggerStateTracker();

        // Per-process cooldown/backoff is handled inside each worker.
        // Global max-triggers is enforced here in the controller as a single counter.
        $max_triggers = $watch_settings->max_triggers;
        $global_trigger_count = 0;
        $quiet = $watch_settings->quiet;

        // Start process searcher (reuses existing daemon infrastructure)
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

        // Create watch worker pool (WatchTriggerMessage-based)
        $num_workers = $daemon_settings->threads;
        /** @var list<PhpWatchControllerInterface> $workers */
        $workers = [];
        /** @var array<int, bool> $worker_free */
        $worker_free = [];
        for ($i = 0; $i < $num_workers; $i++) {
            $worker = $this->php_watch_context_creator->create();
            $worker->start();
            $worker->sendSettings($watch_settings, $loop_settings, $get_trace_settings);
            $workers[] = $worker;
            $worker_free[$i] = true;
        }

        // PID → worker index mapping
        /** @var array<int, int> $pid_to_worker */
        $pid_to_worker = [];

        $_echo_back_canceler = new EchoBackCanceller();
        $cancellation = new DeferredCancellation();

        if (stream_isatty(STDIN)) {
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
        }

        if (!$quiet) {
            $output->writeln(sprintf(
                '<info>[watch-daemon] Monitoring processes matching "%s" | triggers=%s | workers=%d</info>',
                $daemon_settings->target_regex,
                implode(',', array_map(fn (TriggerInterface $t) => $t->name(), $triggers)),
                $num_workers,
            ));
        }

        /** @var array<int, true> $exhausted_pids PIDs whose workers reached max-triggers */
        $exhausted_pids = [];

        $futures = [];

        // Enrich TargetProcessDescriptor with CG address for watch
        $watch_descriptor_retriever
            = new \Reli\Inspector\Watch\Daemon\Searcher\WatchDescriptorRetriever(
                $this->php_globals_finder,
                $this->php_version_detector,
            );

        // Searcher future: discover processes and assign to free workers
        $futures[] = async(function () use (
            $searcher_context,
            $watch_descriptor_retriever,
            $target_php_settings,
            &$workers,
            &$worker_free,
            &$pid_to_worker,
            &$exhausted_pids,
            $output,
            $quiet,
        ) {
            while (1) {
                $update = $searcher_context->receivePidList();
                foreach ($update->target_process_list->getArray() as $descriptor) {
                    $pid = $descriptor->pid;
                    if (isset($pid_to_worker[$pid]) || isset($exhausted_pids[$pid])) {
                        continue;
                    }
                    // Enrich with CG address
                    $watch_desc = $watch_descriptor_retriever
                        ->getDescriptor($pid, $target_php_settings);
                    if ($watch_desc->pid === 0) {
                        continue; // Invalid
                    }
                    // Find a free worker
                    foreach ($worker_free as $idx => $is_free) {
                        if ($is_free) {
                            $worker_free[$idx] = false;
                            $pid_to_worker[$pid] = $idx;
                            $workers[$idx]->sendAttach($watch_desc);
                            if (!$quiet) {
                                $output->writeln(sprintf(
                                    '<info>[+process] PID=%d assigned to worker %d</info>',
                                    $pid,
                                    $idx,
                                ));
                            }
                            break;
                        }
                    }
                }
            }
        });

        // Worker futures: receive trigger events from watch workers.
        // Per-process cooldown is in worker; global max-triggers is here.
        foreach ($workers as $idx => $worker) {
            $futures[] = async(
                function () use (
                    $idx,
                    $worker,
                    $actions,
                    $on_enter_actions,
                    $on_exit_actions,
                    $has_daemon_lifecycle,
                    $daemon_state_tracker,
                    $output,
                    $quiet,
                    $max_triggers,
                    $cancellation,
                    &$global_trigger_count,
                    &$worker_free,
                    &$pid_to_worker,
                    &$exhausted_pids,
                ) {
                    while (1) {
                        $result = $worker->receiveMessage();
                        if ($result instanceof WatchTraceNotifyMessage) {
                            if (!$quiet) {
                                $label = $result->started ? 'TRACE STARTED' : 'TRACE STOPPED';
                                $output->writeln(sprintf(
                                    '<info>[%s] PID=%d | %s</info>',
                                    $label,
                                    $result->pid,
                                    $result->trace_path ?? '',
                                ));
                            }
                        } elseif ($result instanceof WatchTriggerExitMessage) {
                            // Trigger condition cleared — execute on-exit actions
                            if ($has_daemon_lifecycle) {
                                $state_key = $result->pid . ':' . $result->event->trigger_name;
                                $daemon_state_tracker->update($state_key, false);
                                $exit_context = new WatchContext(
                                    pid: $result->pid,
                                    heap_stats: new HeapStats(0, 0, 0, 0),
                                    call_trace: null,
                                    timestamp: $result->event->timestamp,
                                    previous: null,
                                );
                                $process = new \Reli\Lib\Process\ProcessSpecifier($result->pid);
                                if (!$quiet) {
                                    $output->writeln(sprintf(
                                        '<info>[EXIT] PID=%d | trigger=%s | %s</info>',
                                        $result->pid,
                                        $result->event->trigger_name,
                                        $result->event->description,
                                    ));
                                }
                                foreach ($on_exit_actions as $action) {
                                    $action->execute($result->event, $process, $exit_context);
                                }
                            }
                        } elseif ($result instanceof WatchTriggerMessage) {
                            $global_trigger_count++;

                            if ($max_triggers > 0 && $global_trigger_count > $max_triggers) {
                                continue;
                            }

                            $context = new WatchContext(
                                pid: $result->pid,
                                heap_stats: $result->heap_stats,
                                call_trace: $result->call_trace,
                                timestamp: $result->event->timestamp,
                                previous: null,
                                daemon_eg_address: $result->eg_address,
                                daemon_cg_address: $result->cg_address,
                                daemon_php_version: $result->php_version,
                            );

                            $process = new \Reli\Lib\Process\ProcessSpecifier($result->pid);

                            // Lifecycle handling for one-shot on-enter actions (log, exec, etc.)
                            // Continuous trace is handled worker-side.
                            if ($has_daemon_lifecycle) {
                                $state_key = $result->pid . ':' . $result->event->trigger_name;
                                $phase = $daemon_state_tracker->update($state_key, true);
                                if ($phase === TriggerPhase::ENTER) {
                                    if (!$quiet) {
                                        $output->writeln(sprintf(
                                            '<info>[ENTER] PID=%d | trigger=%s | %s</info>',
                                            $result->pid,
                                            $result->event->trigger_name,
                                            $result->event->description,
                                        ));
                                    }
                                    foreach ($on_enter_actions as $action) {
                                        $action->execute($result->event, $process, $context);
                                    }
                                }
                            }

                            if (!$quiet && !$has_daemon_lifecycle) {
                                $output->writeln(sprintf(
                                    '<info>[TRIGGERED] PID=%d | trigger=%s | %s (%d/%s)</info>',
                                    $result->pid,
                                    $result->event->trigger_name,
                                    $result->event->description,
                                    $global_trigger_count,
                                    $max_triggers > 0 ? (string)$max_triggers : 'unlimited',
                                ));
                            }

                            foreach ($actions as $action) {
                                $action->execute($result->event, $process, $context);
                            }

                            if ($max_triggers > 0 && $global_trigger_count >= $max_triggers) {
                                if (!$quiet) {
                                    $output->writeln(sprintf(
                                        '<comment>[watch-daemon] Global max-triggers'
                                        . ' reached (%d/%d), stopping.</comment>',
                                        $global_trigger_count,
                                        $max_triggers,
                                    ));
                                }
                                $cancellation->cancel();
                                return;
                            }
                        } else {
                            // WatchDetachMessage — fire on-exit for one-shot actions
                            if ($has_daemon_lifecycle) {
                                $cleared = $daemon_state_tracker->clearPrefix($result->pid . ':');
                                if (count($cleared) > 0) {
                                    $exit_event = new TriggerEvent(
                                        trigger_name: implode('+', array_map(
                                            fn (string $k) => explode(':', $k, 2)[1] ?? $k,
                                            $cleared,
                                        )),
                                        description: 'process detached',
                                        timestamp: \microtime(true),
                                    );
                                    $exit_context = new WatchContext(
                                        pid: $result->pid,
                                        heap_stats: new HeapStats(0, 0, 0, 0),
                                        call_trace: null,
                                        timestamp: \microtime(true),
                                        previous: null,
                                    );
                                    $process = new \Reli\Lib\Process\ProcessSpecifier($result->pid);
                                    if (!$quiet) {
                                        $output->writeln(sprintf(
                                            '<info>[EXIT] PID=%d | %s | process detached</info>',
                                            $result->pid,
                                            $exit_event->trigger_name,
                                        ));
                                    }
                                    foreach ($on_exit_actions as $action) {
                                        $action->execute($exit_event, $process, $exit_context);
                                    }
                                }
                            }

                            if (!$quiet) {
                                $output->writeln(sprintf(
                                    '<comment>[-process] PID=%d detached from worker %d</comment>',
                                    $result->pid,
                                    $idx,
                                ));
                            }
                            $worker_free[$idx] = true;
                            unset($pid_to_worker[$result->pid]);
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
}
