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
use Reli\Converter\BinaryTrace\CallTraceConverter;
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
use Reli\Inspector\Settings\OutputSettings\TraceOutputPathResolver;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettingsFromConsoleInput;
use Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettingsFromConsoleInput;
use Reli\Lib\Console\EchoBackCanceller;
use Reli\Lib\Log\Log;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;
use Revolt\EventLoop;
use Reli\Command\DockerProfile;
use Reli\Command\ReliCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Amp\async;
use function Amp\Future\await;
use function fread;

use const STDIN;

final class DaemonCommand extends ReliCommand
{
    #[\Override]
    public static function getDockerProfile(): DockerProfile
    {
        return DockerProfile::Full;
    }

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
        $this->addMemoryLimitOption();
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyMemoryLimit($input, $output);
        $no_cache = (bool)$input->getOption('no-cache');
        $get_trace_settings = $this->get_trace_settings_from_console_input->createSettings($input);
        $daemon_settings = $this->daemon_settings_from_console_input->createSettings($input);
        $target_php_settings = $this->target_php_settings_from_console_input->createSettings($input);
        $loop_settings = $this->trace_loop_settings_from_console_input->createSettings($input);
        $output_settings = $this->output_settings_from_console_input->createSettings($input);

        // TraceOutput is only used for template-based text output.
        // Binary modes (rbt, rbt-bundled) handle their own output.
        $trace_output = null;
        if ($output_settings->isTemplate()) {
            $trace_output = $this->trace_output_factory->fromSettingsAndConsoleOutput(
                $output,
                $output_settings,
                $loop_settings,
            );
        }

        // For rbt (per-worker) mode, resolve the output directory.
        // Defaults to XDG_STATE_HOME/reli/daemon-traces/{session}/ when -o is not given.
        $rbt_output_dir = null;
        if ($output_settings->isBinaryTrace()) {
            $rbt_output_dir = TraceOutputPathResolver::resolveRbtOutputDir(
                $output_settings->output_path
            );
            if ($output instanceof \Symfony\Component\Console\Output\ConsoleOutputInterface) {
                $output->getErrorOutput()->writeln("rbt output: {$rbt_output_dir}");
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
            $get_trace_settings->needsCompilerGlobals(),
            $daemon_settings->target_thread_regex,
        );

        // Pass resolved output dir to workers (not the raw user path)
        $worker_output_settings = $rbt_output_dir !== null
            ? new OutputSettings(
                $output_settings->output_format,
                $rbt_output_dir,
                $output_settings->rbt_timestamps,
                $output_settings->rbt_compress,
            )
            : $output_settings;

        $worker_pool = WorkerPool::create(
            $this->php_reader_context_creator,
            $daemon_settings->threads,
            $loop_settings,
            $get_trace_settings,
            $worker_output_settings,
        );

        $dispatch_table = new DispatchTable(
            $worker_pool,
        );

        $_echo_back_canceler = new EchoBackCanceller();

        $cancellation = new DeferredCancellation();

        // Derive sampling period from loop settings (ns → µs)
        $sampling_period_us = (int)($loop_settings->sleep_nano_seconds / 1000);
        if ($sampling_period_us <= 0) {
            $sampling_period_us = 10000;
        }

        // Set up PID_SAMPLE writer for bundled mode
        /** @var BinaryTraceWriter|null $bundled_writer */
        $bundled_writer = null;
        /** @var resource|null $bundled_stream */
        $bundled_stream = null;
        /** @var resource|null $bundled_buffer */
        $bundled_buffer = null;
        $bundled_last_hrtime_ns = null;
        $bundled_compress = $output_settings->rbt_compress;
        if ($output_settings->isBinaryTraceBundled()) {
            $bundled_stream = $output_settings->output_path !== null
                ? (fopen($output_settings->output_path, 'wb') ?: \STDOUT)
                : \STDOUT;
            if ($bundled_compress) {
                $bundled_buffer = fopen('php://temp', 'r+');
                assert($bundled_buffer !== false);
                $write_target = $bundled_buffer;
            } else {
                $write_target = $bundled_stream;
            }
            $bundled_writer = new BinaryTraceWriter(
                $write_target,
                $sampling_period_us,
                has_timestamps: $output_settings->hasRbtTimestamps(),
            );
            $bundled_writer->writeHeader();
        }

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
                                // Bundled mode: write PID_SAMPLE directly,
                                // carrying any --trace-var annotations the
                                // worker attached.
                                $this->writeBundledTrace(
                                    $bundled_writer,
                                    $result->trace,
                                    $result->pid,
                                    $bundled_last_hrtime_ns,
                                    $result->annotations,
                                );
                            } elseif ($trace_output !== null) {
                                // Template mode: use TraceOutput, passing
                                // annotations through to the formatter.
                                $trace_output->output($result->trace, $result->annotations);
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
        } finally {
            $this->closeBundledWriter(
                $bundled_writer,
                $bundled_buffer,
                $bundled_stream,
                $bundled_compress,
            );
        }

        return 0;
    }

    private function closeBundledWriter(
        ?BinaryTraceWriter $writer,
        mixed $buffer,
        mixed $stream,
        bool $compress,
    ): void {
        if ($writer !== null) {
            $writer->writeCheckpoint();
            $writer->writeSegmentEnd();
        }
        if ($compress && is_resource($buffer) && is_resource($stream)) {
            rewind($buffer);
            $raw = stream_get_contents($buffer);
            fclose($buffer);
            if ($raw !== false && $raw !== '') {
                $compressed = gzencode($raw);
                if ($compressed !== false) {
                    fwrite($stream, $compressed);
                }
            }
        }
        if (is_resource($stream) && $stream !== \STDOUT) {
            fclose($stream);
        }
    }

    /**
     * @param array<string, string>|null $annotations
     */
    private function writeBundledTrace(
        BinaryTraceWriter $writer,
        CallTrace $call_trace,
        int $pid,
        ?int &$last_hrtime_ns,
        ?array $annotations = null,
    ): void {
        $now_ns = hrtime(true);
        $delta_us = 0;
        if ($last_hrtime_ns !== null) {
            $delta_us = (int)(($now_ns - $last_hrtime_ns) / 1000);
        }
        $last_hrtime_ns = $now_ns;

        $writer->writePidTrace(
            CallTraceConverter::toParsed($call_trace),
            $pid,
            $delta_us,
            $annotations,
        );

        if ($writer->getSamplesSinceCheckpoint() >= 1000) {
            $writer->writeCheckpoint();
        }
    }
}
