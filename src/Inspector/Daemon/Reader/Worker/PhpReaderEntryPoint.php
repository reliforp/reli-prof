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
        $binary_output_dir = $use_binary_direct ? $output_settings->output_path : null;
        if ($use_binary_direct && $output_settings->rbt_compress) {
            $this->compress = true;
        }
        $has_timestamps = $output_settings !== null && $output_settings->hasRbtTimestamps();

        // Derive sampling period from loop settings (ns → µs)
        $sampling_period_us = (int)(
            $set_settings_message->trace_loop_settings->sleep_nano_seconds / 1000
        );
        if ($sampling_period_us <= 0) {
            $sampling_period_us = 10000;
        }

        $this->installSignalHandler();

        while ($this->loop_condition->shouldContinue()) {
            $attach_message = $this->protocol->receiveAttach();
            Log::debug('attach_message', [$attach_message]);
            $pid = $attach_message->process_descriptor->pid;

            // Writer construction, header/metadata emission, and (for
            // the worker's first segment) the underlying file open are
            // all deferred to the first non-empty trace. An attach that
            // observes only the trace loop's idle ride-through markers
            // (empty CallTrace) for its whole lifetime therefore writes
            // no segment, leaving no header+metadata-only artifact in
            // the output directory. The previous attach's intern state
            // does not carry over either: a fresh BinaryTraceWriter is
            // built on the first sample of this attach.
            $this->last_hrtime_ns = null;

            try {
                $loop_runner = $this->trace_loop->run(
                    $set_settings_message->trace_loop_settings,
                    $attach_message->process_descriptor,
                    $set_settings_message->get_trace_settings
                );
                Log::debug('start trace');
                foreach ($loop_runner as $message) {
                    if ($message->trace->call_frames === []) {
                        // Idle ride-through marker from PhpReaderTraceLoop:
                        // the target had no PHP frames in flight at this
                        // tick. The yield exists only to keep the AsyncLoop
                        // alive (the retry middleware deadlocks on a
                        // chain that returns without yielding); drop it
                        // before any writer or IPC sees it. Genuine idle
                        // intervals show up as gaps between samples in
                        // timestamped (--rbt-timestamps=delta) rbt output.
                        continue;
                    }
                    if ($use_binary_direct) {
                        if ($this->binary_writer === null) {
                            $this->openBinarySegment(
                                $pid,
                                $sampling_period_us,
                                $has_timestamps,
                                $binary_output_dir,
                            );
                        }
                        if ($this->binary_writer !== null) {
                            // Per-worker rbt mode: annotations never leave
                            // the worker — they go straight into our own
                            // .rbt file.
                            $this->writeBinaryTrace($message->trace, $message->annotations);
                        }
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

            // Close the segment cleanly on detach. binary_writer is
            // non-null only when at least one real sample reached us
            // for this attach; an attach that produced no samples
            // leaves no segment behind (and, if this was the worker's
            // first attach, also no file).
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

    /**
     * Lazily open the per-worker output file (if not already open) and
     * construct a fresh BinaryTraceWriter for the current attach,
     * emitting the rbt header and the per-segment `pid` metadata. Called
     * from inside the trace loop on the first non-empty TraceMessage of
     * each attach, so a worker that only ever observes empty markers
     * never reaches this path.
     */
    private function openBinarySegment(
        int $pid,
        int $sampling_period_us,
        bool $has_timestamps,
        ?string $binary_output_dir,
    ): void {
        if ($this->binary_stream === null && $binary_output_dir !== null) {
            $this->openBinaryStream($binary_output_dir);
        }
        if ($this->binary_stream === null) {
            return;
        }
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
