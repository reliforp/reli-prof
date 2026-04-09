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

namespace Reli\Inspector\Daemon\Reader\Worker;

use Reli\Converter\BinaryTrace\BinaryTraceWriter;
use Reli\Converter\ParsedCallFrame;
use Reli\Converter\ParsedCallTrace;
use Reli\Inspector\Daemon\Reader\Protocol\Message\DetachWorkerMessage;
use Reli\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;
use Reli\Inspector\Daemon\Reader\Protocol\PhpReaderWorkerProtocolInterface;
use Reli\Lib\Amphp\WorkerEntryPointInterface;
use Reli\Lib\Log\Log;
use Reli\Lib\Loop\LoopCondition\InfiniteLoopCondition;
use Reli\Lib\Loop\LoopCondition\LoopConditionInterface;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;

final class PhpReaderEntryPoint implements WorkerEntryPointInterface
{
    private ?BinaryTraceWriter $binary_writer = null;
    /** @var resource|null */
    private $binary_stream = null;
    private ?int $last_hrtime_ns = null;

    public function __construct(
        private PhpReaderTraceLoopInterface $trace_loop,
        private PhpReaderWorkerProtocolInterface $protocol,
        private LoopConditionInterface $loop_condition = new InfiniteLoopCondition(),
    ) {
    }

    #[\Override]
    public function run(): void
    {
        $set_settings_message = $this->protocol->receiveSettings();
        Log::debug('settings_message', [$set_settings_message]);

        $output_settings = $set_settings_message->output_settings;
        $use_binary_direct = $output_settings !== null && $output_settings->isBinaryTrace();

        if ($use_binary_direct && $output_settings->output_path !== null) {
            $this->setupBinaryWriter($output_settings->output_path);
        }

        $this->installSignalHandler();

        while ($this->loop_condition->shouldContinue()) {
            $attach_message = $this->protocol->receiveAttach();
            Log::debug('attach_message', [$attach_message]);
            $pid = $attach_message->process_descriptor->pid;

            if ($use_binary_direct && $this->binary_writer !== null) {
                $this->binary_writer->writeMetadata('pid', (string)$pid);
            }

            try {
                $loop_runner = $this->trace_loop->run(
                    $set_settings_message->trace_loop_settings,
                    $attach_message->process_descriptor,
                    $set_settings_message->get_trace_settings
                );
                Log::debug('start trace');
                foreach ($loop_runner as $message) {
                    if ($use_binary_direct && $this->binary_writer !== null) {
                        $this->writeBinaryTrace($message->trace);
                    } else {
                        $message = new TraceMessage($message->trace, $pid);
                        $this->protocol->sendTrace($message);
                    }
                }
                Log::debug('end trace');
            } catch (\Throwable $e) {
                Log::debug('exception thrown at reading traces', [
                    'exception' => $e,
                    'trace' => $e->getTrace(),
                ]);
            }

            if ($use_binary_direct && $this->binary_writer !== null) {
                $this->binary_writer->writeCheckpoint();
                $this->binary_writer->writeSegmentEnd();
            }

            Log::debug('detaching worker');
            $this->protocol->sendDetachWorker(
                new DetachWorkerMessage($pid)
            );
            Log::debug('detached worker');
        }

        $this->closeBinaryWriter();
    }

    private function setupBinaryWriter(string $output_dir): void
    {
        $my_pid = getmypid();
        $worker_id = $my_pid !== false ? $my_pid : 0;
        $path = rtrim($output_dir, '/') . "/worker_{$worker_id}.rbt";
        $stream = fopen($path, 'wb');
        if ($stream === false) {
            Log::debug('failed to open binary trace file', ['path' => $path]);
            return;
        }
        $this->binary_stream = $stream;
        $this->binary_writer = new BinaryTraceWriter($stream, 10000, has_timestamps: true);
        $this->binary_writer->writeHeader();
    }

    private function writeBinaryTrace(CallTrace $call_trace): void
    {
        assert($this->binary_writer !== null);
        $now_ns = hrtime(true);
        $delta_us = 0;
        if ($this->last_hrtime_ns !== null) {
            $delta_us = (int)(($now_ns - $this->last_hrtime_ns) / 1000);
        }
        $this->last_hrtime_ns = $now_ns;

        $frames = [];
        foreach ($call_trace->call_frames as $call_frame) {
            $frames[] = new ParsedCallFrame(
                $call_frame->getFullyQualifiedFunctionName(),
                $call_frame->file_name,
                $call_frame->getLineno(),
            );
        }
        $this->binary_writer->writeTrace(new ParsedCallTrace(...$frames), $delta_us);

        if ($this->binary_writer->getSamplesSinceCheckpoint() >= 1000) {
            $this->binary_writer->writeCheckpoint();
        }
    }

    private function closeBinaryWriter(): void
    {
        if ($this->binary_writer !== null) {
            $this->binary_writer->writeCheckpoint();
            $this->binary_writer->writeSegmentEnd();
            $this->binary_writer = null;
        }
        if ($this->binary_stream !== null) {
            $stream = $this->binary_stream;
            $this->binary_stream = null;
            fclose($stream);
        }
    }

    private function installSignalHandler(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function (): void {
                Log::debug('SIGTERM received in worker, closing binary writer');
                $this->closeBinaryWriter();
                exit(0);
            });
        }
    }
}
