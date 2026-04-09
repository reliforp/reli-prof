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
use Reli\Converter\BinaryTrace\BinaryTraceWriter;
use Reli\Converter\ParsedCallFrame;
use Reli\Converter\ParsedCallTrace;
use Reli\Inspector\Daemon\Dispatcher\DispatchTable;
use Reli\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;
use Reli\Inspector\Daemon\Dispatcher\WorkerPool;
use Reli\Inspector\Daemon\Reader\Context\PhpReaderContextCreator;
use Reli\Inspector\Daemon\Searcher\Context\PhpSearcherContextCreator;
use Reli\Inspector\Output\TraceOutput\TraceOutput;
use Reli\Inspector\Output\TraceOutput\TraceOutputFactory;
use Reli\Inspector\Settings\DaemonSettings\DaemonSettingsFromConsoleInput;
use Reli\Inspector\Settings\GetTraceSettings\GetTraceSettingsFromConsoleInput;
use Reli\Inspector\Settings\OutputSettings\OutputSettings;
use Reli\Inspector\Settings\OutputSettings\OutputSettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettingsFromConsoleInput;
use Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettingsFromConsoleInput;
use Reli\Lib\Console\EchoBackCanceller;
use Reli\Lib\Log\Log;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;
use Revolt\EventLoop;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Amp\async;
use function Amp\Future\await;
use function fread;

use const STDIN;

final class DaemonCommand extends Command
{
    public function __construct(
        private PhpSearcherContextCreator $php_searcher_context_creator,
        private PhpReaderContextCreator $php_reader_context_creator,
        private DaemonSettingsFromConsoleInput $daemon_settings_from_console_input,
        private GetTraceSettingsFromConsoleInput $get_trace_settings_from_console_input,
        private TargetPhpSettingsFromConsoleInput $target_php_settings_from_console_input,
        private TraceLoopSettingsFromConsoleInput $trace_loop_settings_from_console_input,
        private OutputSettingsFromConsoleInput $output_settings_from_console_input,
        private TraceOutputFactory $trace_output_factory,
    ) {
        parent::__construct();
    }

    #[\Override]
    public function configure(): void
    {
        $this->setName('inspector:daemon')
            ->setDescription('concurrently get call traces from processes whose command-lines match a given regex')
        ;
        $this->daemon_settings_from_console_input->setOptions($this);
        $this->get_trace_settings_from_console_input->setOptions($this);
        $this->trace_loop_settings_from_console_input->setOptions($this);
        $this->target_php_settings_from_console_input->setOptions($this);
        $this->output_settings_from_console_input->setOptions($this);
        $this->addOption('no-cache', null, InputOption::VALUE_NONE, 'disable the binary analysis cache');
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $no_cache = (bool)$input->getOption('no-cache');
        $get_trace_settings = $this->get_trace_settings_from_console_input->createSettings($input);
        $daemon_settings = $this->daemon_settings_from_console_input->createSettings($input);
        $target_php_settings = $this->target_php_settings_from_console_input->createSettings($input);
        $loop_settings = $this->trace_loop_settings_from_console_input->createSettings($input);
        $output_settings = $this->output_settings_from_console_input->createSettings($input);

        $trace_output = $this->trace_output_factory->fromSettingsAndConsoleOutput(
            $output,
            $output_settings,
        );

        // For rbt (per-worker) mode, ensure output_path is a directory
        if ($output_settings->isBinaryTrace() && $output_settings->output_path !== null) {
            if (!is_dir($output_settings->output_path)) {
                mkdir($output_settings->output_path, 0755, true);
            }
        }

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
            $get_trace_settings,
            $output_settings,
        );

        $dispatch_table = new DispatchTable(
            $worker_pool,
        );

        $_echo_back_canceler = new EchoBackCanceller();

        $cancellation = new DeferredCancellation();

        // Set up PID_SAMPLE writer for bundled mode
        $bundled_writer = null;
        $bundled_last_hrtime_ns = null;
        if ($output_settings->isBinaryTraceBundled()) {
            $stream = $output_settings->output_path !== null
                ? (fopen($output_settings->output_path, 'wb') ?: \STDOUT)
                : \STDOUT;
            $bundled_writer = new BinaryTraceWriter($stream, 10000, has_timestamps: true);
            $bundled_writer->writeHeader();
        }

        if (stream_isatty(STDIN)) {
            EventLoop::onReadable(
                STDIN,
                /** @param resource $stream */
                function (string $watcher_id, $stream) use ($cancellation, $bundled_writer) {
                    $key = fread($stream, 1);
                    if ($key === 'q') {
                        EventLoop::cancel($watcher_id);
                        if ($bundled_writer !== null) {
                            $bundled_writer->writeCheckpoint();
                            $bundled_writer->writeSegmentEnd();
                        }
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
                function () use (
                    $reader,
                    $dispatch_table,
                    $trace_output,
                    $output_settings,
                    $bundled_writer,
                    &$bundled_last_hrtime_ns,
                ) {
                    while (1) {
                        $result = $reader->receiveTraceOrDetachWorker();
                        if ($result instanceof TraceMessage) {
                            if ($bundled_writer !== null && $result->pid !== null) {
                                // Bundled mode: write PID_SAMPLE directly
                                $this->writeBundledTrace(
                                    $bundled_writer,
                                    $result->trace,
                                    $result->pid,
                                    $bundled_last_hrtime_ns,
                                );
                            } elseif (!$output_settings->isBinaryTrace()) {
                                // Template mode or rbt-bundled without PID: use TraceOutput
                                $trace_output->output($result->trace);
                            }
                            // rbt per-worker mode: traces written by worker directly, nothing to do here
                        } else {
                            Log::debug('releaseOne', [$result]);
                            $dispatch_table->releaseOne($result->pid);
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

    private function writeBundledTrace(
        BinaryTraceWriter $writer,
        CallTrace $call_trace,
        int $pid,
        ?int &$last_hrtime_ns,
    ): void {
        $now_ns = hrtime(true);
        $delta_us = 0;
        if ($last_hrtime_ns !== null) {
            $delta_us = (int)(($now_ns - $last_hrtime_ns) / 1000);
        }
        $last_hrtime_ns = $now_ns;

        $frames = [];
        foreach ($call_trace->call_frames as $call_frame) {
            $frames[] = new ParsedCallFrame(
                $call_frame->getFullyQualifiedFunctionName(),
                $call_frame->file_name,
                $call_frame->getLineno(),
            );
        }
        $writer->writePidTrace(new ParsedCallTrace(...$frames), $pid, $delta_us);

        if ($writer->getSamplesSinceCheckpoint() >= 1000) {
            $writer->writeCheckpoint();
        }
    }
}
