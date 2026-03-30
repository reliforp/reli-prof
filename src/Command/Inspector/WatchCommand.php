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
use Reli\Lib\Log\Log;
use Revolt\EventLoop;
use Reli\Inspector\Watch\Action\ActionInterface;
use Reli\Inspector\Watch\ActionFactory;
use Reli\Inspector\Watch\CooldownManager;
use Reli\Inspector\Watch\DiskUsageTracker;
use Reli\Inspector\Watch\HeapStats;
use Reli\Inspector\Watch\HeapStatsReader;
use Reli\Inspector\Watch\TriggerFactory;
use Reli\Inspector\Watch\VariableReader;
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
        /** @var list<VariableValueTrigger> $var_triggers */
        $var_triggers = [];
        foreach ($triggers as $trigger) {
            if ($trigger->requiresCallTrace()) {
                $needs_call_trace = true;
            }
            if ($trigger instanceof VariableValueTrigger) {
                $var_triggers[] = $trigger;
            }
        }

        // Build actions
        $trace_output = $this->trace_output_factory->fromSettingsAndConsoleOutput(
            $output,
            $output_settings,
        );
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

        $php_version = $target_php_settings->php_version;
        $depth = $get_trace_settings->depth;
        $stop_process = (bool)($input->getOption('stop-process') ?? false);
        $quiet = $watch_settings->quiet;

        $previous_context = null;
        $poll_count = 0;
        $action_count = 0;

        $loop_settings = new TraceLoopSettings(
            sleep_nano_seconds: $watch_settings->poll_interval_ms * 1000 * 1000,
            cancel_key: 'q',
            max_retries: 0, // no retry — we handle errors per poll
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
                $cg_address,
                $php_version,
                $depth,
                $stop_process,
                $needs_call_trace,
                $var_triggers,
                $triggers,
                $actions,
                $cooldown,
                $disk_tracker,
                $quiet,
                $watch_settings,
                $output,
                &$previous_context,
                &$poll_count,
                &$action_count,
            ): bool {
                $poll_count++;
                $now = microtime(true);

                // Stop early if max action executions already reached
                if (
                    $watch_settings->max_triggers > 0
                    && $action_count >= $watch_settings->max_triggers
                ) {
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

                    $call_trace = null;
                    if ($needs_call_trace) {
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
                    if (count($var_triggers) > 0) {
                        $variable_values = $this->variable_reader
                            ->readVariables(
                                $var_triggers,
                                $process_specifier,
                                $target_php_settings,
                                $eg_address,
                                $cg_address,
                            );
                    }
                } catch (\Throwable) {
                    // Target may be between requests or temporarily
                    // unreadable. Skip this poll, try next cycle.
                    $previous_context = null;
                    return true;
                }

                $context = new WatchContext(
                    pid: $process_specifier->pid,
                    heap_stats: $heap_stats,
                    call_trace: $call_trace,
                    timestamp: $now,
                    previous: $previous_context,
                    variable_values: $variable_values,
                );

                // Evaluate triggers — collect all fired events in this poll
                /** @var list<TriggerEvent> $fired_events */
                $fired_events = [];
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

                    $cooldown->recordFire($trigger->name(), $now);
                    $fired_events[] = $event;

                    if (!$quiet) {
                        $output->writeln(sprintf(
                            '<info>[TRIGGERED] PID=%d | trigger=%s | %s</info>',
                            $process_specifier->pid,
                            $event->trigger_name,
                            $event->description,
                        ));
                    }
                }

                // Execute actions once per poll (not per trigger)
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
                    $output->write(sprintf(
                        "\r<info>[watching]</info> PID=%d | mem=%s/%s | polls=%d | triggers=%d | disk=%s",
                        $process_specifier->pid,
                        HeapStats::humanReadableBytes($heap_stats->size),
                        HeapStats::humanReadableBytes(
                            $heap_stats->limit > 0
                                ? $heap_stats->limit
                                : $heap_stats->real_size
                        ),
                        $poll_count,
                        $cooldown->getTotalFires(),
                        HeapStats::humanReadableBytes($disk_tracker->getTotalBytes()),
                    ));
                }

                $previous_context = $context;

                return true;
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
                        $result = $worker->receiveTriggerOrDetach();
                        if ($result instanceof WatchTriggerMessage) {
                            $global_trigger_count++;

                            if ($max_triggers > 0 && $global_trigger_count > $max_triggers) {
                                // Silently drop — already past limit
                                continue;
                            }

                            if (!$quiet) {
                                $output->writeln(sprintf(
                                    '<info>[TRIGGERED] PID=%d | trigger=%s | %s (%d/%s)</info>',
                                    $result->pid,
                                    $result->event->trigger_name,
                                    $result->event->description,
                                    $global_trigger_count,
                                    $max_triggers > 0 ? (string)$max_triggers : 'unlimited',
                                ));
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

                            foreach ($actions as $action) {
                                $action->execute(
                                    $result->event,
                                    new \Reli\Lib\Process\ProcessSpecifier($result->pid),
                                    $context,
                                );
                            }

                            // Cancel all workers when global limit reached
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
