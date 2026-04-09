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
use Reli\Inspector\Output\TopLike\TopLikeFormatter;
use Reli\Inspector\Output\TopLike\TopLikeFormatterFactory;
use Reli\Inspector\RetryingLoopProvider;
use Reli\Inspector\Settings\DaemonSettings\DaemonSettingsFromConsoleInput;
use Reli\Inspector\Settings\GetTraceSettings\GetTraceSettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetProcessSettings\TargetProcessSettingsFromConsoleInput;
use Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettingsFromConsoleInput;
use Reli\Inspector\TargetProcess\TargetProcessResolver;
use Reli\Inspector\TraceLoopProvider;
use Reli\Lib\Console\EchoBackCanceller;
use Reli\Lib\Elf\Process\BinaryAnalysisCache;
use Reli\Lib\Log\Log;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader;
use Reli\Lib\PhpProcessReader\CallTraceReader\TraceCache;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpVersionDetector;
use Reli\Lib\Process\ProcessStopper\ProcessStopper;
use Revolt\EventLoop;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Amp\async;
use function Amp\Future\await;
use function Reli\Lib\Defer\defer;

final class TopLikeCommand extends Command
{
    public function __construct(
        private PhpSearcherContextCreator $php_searcher_context_creator,
        private PhpReaderContextCreator $php_reader_context_creator,
        private DaemonSettingsFromConsoleInput $daemon_settings_from_console_input,
        private GetTraceSettingsFromConsoleInput $get_trace_settings_from_console_input,
        private TargetPhpSettingsFromConsoleInput $target_php_settings_from_console_input,
        private TraceLoopSettingsFromConsoleInput $trace_loop_settings_from_console_input,
        private TopLikeFormatterFactory $formatter_factory,
        private TargetProcessSettingsFromConsoleInput $target_process_settings_from_console_input,
        private TargetProcessResolver $target_process_resolver,
        private PhpGlobalsFinder $php_globals_finder,
        private PhpVersionDetector $php_version_detector,
        private CallTraceReader $call_trace_reader,
        private TraceLoopProvider $loop_provider,
        private ProcessStopper $process_stopper,
        private RetryingLoopProvider $retrying_loop_provider,
        private BinaryAnalysisCache $binary_analysis_cache,
    ) {
        parent::__construct();
    }

    #[\Override]
    public function configure(): void
    {
        $this->setName('inspector:top')
            ->setDescription(
                'show an aggregated view of traces in real time in a form similar to the UNIX top command.'
            )
        ;
        $this->target_process_settings_from_console_input->setOptions($this);
        $this->daemon_settings_from_console_input->setOptions($this);
        $this->get_trace_settings_from_console_input->setOptions($this);
        $this->trace_loop_settings_from_console_input->setOptions($this);
        $this->target_php_settings_from_console_input->setOptions($this);
        $this->addOption('no-cache', null, InputOption::VALUE_NONE, 'disable the binary analysis cache');
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $target_regex = $input->getOption('target-regex');
        if ($target_regex !== null) {
            return $this->executeDaemonMode($input, $output);
        }
        return $this->executeSingleProcessMode($input, $output);
    }

    private function executeDaemonMode(InputInterface $input, OutputInterface $output): int
    {
        $no_cache = (bool)$input->getOption('no-cache');
        $get_trace_settings = $this->get_trace_settings_from_console_input->createSettings($input);
        $daemon_settings = $this->daemon_settings_from_console_input->createSettings($input);
        $target_php_settings = $this->target_php_settings_from_console_input->createSettings($input);
        $loop_settings = $this->trace_loop_settings_from_console_input->createSettings($input);
        $formatter = $this->formatter_factory->create(
            $daemon_settings->target_regex,
            $output
        );

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

        $worker_pool = WorkerPool::create(
            $this->php_reader_context_creator,
            $daemon_settings->threads,
            $loop_settings,
            $get_trace_settings
        );

        $dispatch_table = new DispatchTable(
            $worker_pool,
        );

        $_echo_back_canceler = new EchoBackCanceller();

        $cancellation = new DeferredCancellation();

        if (stream_isatty(STDIN)) {
            EventLoop::onReadable(
                STDIN,
                /** @param resource $stream */
                function (string $watcher_id, $stream) use ($cancellation) {
                    $key = fread($stream, 1);
                    if ($key === 'q') {
                        EventLoop::cancel($watcher_id);
                        $cancellation->cancel();
                    }
                }
            );
        }
        $futures = [];
        $futures[] = async(function () use ($searcher_context, $dispatch_table) {
            while (1) {
                Log::debug('receiving pid List');
                $update_target_message = $searcher_context->receivePidList();
                Log::debug('update targets', [
                    'update' => $update_target_message->target_process_list->getArray(),
                    'current' => $dispatch_table->worker_pool->debugDump(),
                ]);
                $dispatch_table->updateTargets($update_target_message->target_process_list);
                Log::debug('target updated', [$dispatch_table->worker_pool->debugDump()]);
            }
        });
        foreach ($worker_pool->getWorkers() as $reader) {
            $futures[] = async(
                function () use ($reader, $dispatch_table, $formatter) {
                    while (1) {
                        $result = $reader->receiveTraceOrDetachWorker();
                        if ($result instanceof TraceMessage) {
                            $this->outputTrace($formatter, $result);
                        } else {
                            Log::debug('releaseOne', [$result]);
                            $dispatch_table->releaseOne($result->pid);
                            $this->outputTrace($formatter, new TraceMessage(
                                new CallTrace()
                            ));
                        }
                    }
                }
            );
        }

        try {
            await($futures, $cancellation->getCancellation());
        } catch (CancelledException $e) {
            Log::debug('cancelled', ['exception' => $e->getMessage()]);
        }

        return 0;
    }

    private function executeSingleProcessMode(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('no-cache')) {
            $this->binary_analysis_cache->disable();
        }
        $get_trace_settings = $this->get_trace_settings_from_console_input->createSettings($input);
        $target_php_settings = $this->target_php_settings_from_console_input->createSettings($input);
        $target_process_settings = $this->target_process_settings_from_console_input->createSettings($input);
        $loop_settings = $this->trace_loop_settings_from_console_input->createSettings($input);

        if ($get_trace_settings->bulk_stack_copy_max_size !== null) {
            $this->call_trace_reader->enableBulkStackCopy($get_trace_settings->bulk_stack_copy_max_size);
        }

        $process_specifier = $this->target_process_resolver->resolve($target_process_settings);

        $trace_target = $target_process_settings->command
            ?? "pid={$process_specifier->pid}";
        $formatter = $this->formatter_factory->create($trace_target, $output);

        $target_php_settings = $this->php_version_detector->decidePhpVersion(
            $process_specifier,
            $target_php_settings
        );

        $eg_address = $this->retrying_loop_provider->do(
            try: fn () => $this->php_globals_finder->findExecutorGlobals(
                $process_specifier,
                $target_php_settings
            ),
            retry_on: [\Throwable::class],
            max_retry: 10,
            interval_on_retry_ns: 1000 * 1000 * 10,
        );

        $sg_address = $this->php_globals_finder->findSAPIGlobals(
            $process_specifier,
            $target_php_settings
        );

        $trace_cache = new TraceCache();

        $this->loop_provider->getMainLoop(
            function () use (
                $get_trace_settings,
                $process_specifier,
                $target_php_settings,
                $loop_settings,
                $eg_address,
                $sg_address,
                $formatter,
                $trace_cache,
            ): bool {
                if ($loop_settings->stop_process and $this->process_stopper->stop($process_specifier->pid)) {
                    defer($_, fn () => $this->process_stopper->resume($process_specifier->pid));
                }
                $call_trace = $this->call_trace_reader->readCallTrace(
                    $process_specifier->pid,
                    $target_php_settings->php_version,
                    $eg_address,
                    $sg_address,
                    $get_trace_settings->depth,
                    $trace_cache
                );
                $formatter->format($call_trace ?? new CallTrace());
                return true;
            },
            $loop_settings
        )->invoke();

        return 0;
    }

    private function outputTrace(
        TopLikeFormatter $formatter,
        TraceMessage $message
    ): void {
        $formatter->format($message->trace);
    }
}
