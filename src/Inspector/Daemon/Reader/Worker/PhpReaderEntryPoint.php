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
use Reli\Converter\BinaryTrace\CallTraceConverter;
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
    /** @var resource|null temp buffer for compressed segment */
    private $segment_buffer = null;
    private bool $compress = false;
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

        // Derive sampling period from loop settings (ns → µs)
        $sampling_period_us = (int)(
            $set_settings_message->trace_loop_settings->sleep_nano_seconds / 1000
        );
        if ($sampling_period_us <= 0) {
            $sampling_period_us = 10000;
        }

        // Open the output file once; segments share the stream
        if ($use_binary_direct && $output_settings->output_path !== null) {
            $this->compress = $output_settings->rbt_compress;
            $this->openBinaryStream($output_settings->output_path);
        }

        $this->installSignalHandler();

        while ($this->loop_condition->shouldContinue()) {
            $attach_message = $this->protocol->receiveAttach();
            Log::debug('attach_message', [$attach_message]);
            $pid = $attach_message->process_descriptor->pid;

            // Start a new self-contained segment for this attach.
            // A fresh BinaryTraceWriter resets frame/stack intern state.
            $has_timestamps = $output_settings !== null && $output_settings->hasRbtTimestamps();
            if ($use_binary_direct && $this->binary_stream !== null) {
                if ($this->compress) {
                    $buf = fopen('php://temp', 'r+');
                    assert($buf !== false);
                    $this->segment_buffer = $buf;
                    $write_target = $this->segment_buffer;
                } else {
                    $write_target = $this->binary_stream;
                }
                $this->binary_writer = new BinaryTraceWriter(
                    $write_target,
                    $sampling_period_us,
                    has_timestamps: $has_timestamps,
                );
                $this->binary_writer->writeHeader();
                $this->binary_writer->writeMetadata('pid', (string)$pid);
                $this->last_hrtime_ns = null;
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
                        // Per-worker rbt mode: annotations never leave the
                        // worker — they go straight into our own .rbt file.
                        $this->writeBinaryTrace($message->trace, $message->annotations);
                    } else {
                        // Bundled / template modes: forward annotations to
                        // the controller via IPC for it to write out.
                        $this->protocol->sendTrace(
                            new TraceMessage($message->trace, $pid, $message->annotations)
                        );
                    }
                }
                Log::debug('end trace');
            } catch (\Throwable $e) {
                Log::debug('exception thrown at reading traces', [
                    'exception' => $e,
                    'trace' => $e->getTrace(),
                ]);
            }

            // Close the segment cleanly on detach
            if ($this->binary_writer !== null) {
                $this->binary_writer->writeCheckpoint();
                $this->binary_writer->writeSegmentEnd();
                $this->binary_writer = null;
                $this->flushCompressedSegment();
            }

            Log::debug('detaching worker');
            $this->protocol->sendDetachWorker(
                new DetachWorkerMessage($pid)
            );
            Log::debug('detached worker');
        }

        $this->closeBinaryStream();
    }

    private function openBinaryStream(string $output_dir): void
    {
        $my_pid = getmypid();
        $worker_id = $my_pid !== false ? $my_pid : 0;
        $ext = $this->compress ? '.rbt.gz' : '.rbt';
        $path = rtrim($output_dir, '/') . "/worker_{$worker_id}{$ext}";
        $stream = fopen($path, 'wb');
        if ($stream === false) {
            Log::debug('failed to open binary trace file', ['path' => $path]);
            return;
        }
        $this->binary_stream = $stream;
    }

    /**
     * @param array<string, string>|null $annotations
     */
    private function writeBinaryTrace(CallTrace $call_trace, ?array $annotations = null): void
    {
        assert($this->binary_writer !== null);
        $now_ns = hrtime(true);
        $delta_us = 0;
        if ($this->last_hrtime_ns !== null) {
            $delta_us = (int)(($now_ns - $this->last_hrtime_ns) / 1000);
        }
        $this->last_hrtime_ns = $now_ns;

        $this->binary_writer->writeTrace(
            CallTraceConverter::toParsed($call_trace),
            $delta_us,
            $annotations,
        );

        if ($this->binary_writer->getSamplesSinceCheckpoint() >= 1000) {
            $this->binary_writer->writeCheckpoint();
        }
    }

    /**
     * Close the current segment (if open) and the underlying stream.
     * Called on clean shutdown (SIGTERM or loop exit).
     */
    private function closeBinaryStream(): void
    {
        if ($this->binary_writer !== null) {
            $this->binary_writer->writeCheckpoint();
            $this->binary_writer->writeSegmentEnd();
            $this->binary_writer = null;
            $this->flushCompressedSegment();
        }
        if ($this->binary_stream !== null) {
            $stream = $this->binary_stream;
            $this->binary_stream = null;
            fclose($stream);
        }
    }

    /**
     * If compressing, gzencode the temp segment buffer and append to output file.
     */
    private function flushCompressedSegment(): void
    {
        if ($this->segment_buffer === null) {
            return;
        }

        $buf = $this->segment_buffer;
        $this->segment_buffer = null;
        rewind($buf);
        $raw = stream_get_contents($buf);
        fclose($buf);

        if ($raw === false || $raw === '' || $this->binary_stream === null) {
            return;
        }

        $compressed = gzencode($raw);
        if ($compressed === false) {
            return;
        }
        fwrite($this->binary_stream, $compressed);
    }

    private function installSignalHandler(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function (): void {
                Log::debug('SIGTERM received in worker, closing binary stream');
                $this->closeBinaryStream();
                exit(0);
            });
        }
    }
}
