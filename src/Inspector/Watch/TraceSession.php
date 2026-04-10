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

namespace Reli\Inspector\Watch;

use Reli\Converter\BinaryTrace\BinaryTraceWriter;
use Reli\Inspector\Output\TraceOutput\BinaryTraceOutput;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;

/**
 * Manages the lifecycle of a continuous trace recording within watch.
 *
 * - start(): create a new .rbt file and begin recording
 * - recordSample(): write a trace sample (called each poll while active)
 * - stop(): finalize and close the .rbt file
 *
 * Only one session can be active at a time per instance.
 */
final class TraceSession
{
    private ?BinaryTraceOutput $output = null;
    private ?string $current_path = null;

    /**
     * @param string $output_dir  Directory for trace output files
     * @param int $sampling_period_us  Sampling period in microseconds for .rbt header
     */
    public function __construct(
        private string $output_dir,
        private int $sampling_period_us = 10000,
    ) {
    }

    /**
     * Start a new trace recording session.
     *
     * If a session is already active, this is a no-op.
     */
    public function start(int $pid, float $timestamp): void
    {
        if ($this->output !== null) {
            return;
        }

        $date = \date('Ymd-His', (int)$timestamp);
        $ms = \sprintf('%03d', (int)(($timestamp - \floor($timestamp)) * 1000));
        $this->current_path = \sprintf(
            '%s/watch-trace-%d-%s.%s.rbt',
            $this->output_dir,
            $pid,
            $date,
            $ms,
        );

        $stream = \fopen($this->current_path, 'wb');
        if ($stream === false) {
            throw new \RuntimeException("Failed to open trace output: {$this->current_path}");
        }

        $writer = new BinaryTraceWriter(
            $stream,
            $this->sampling_period_us,
            has_timestamps: true,
        );
        $this->output = new BinaryTraceOutput($writer);
    }

    /**
     * Record a trace sample. No-op if no session is active.
     */
    public function recordSample(CallTrace $call_trace): void
    {
        $this->output?->output($call_trace);
    }

    /**
     * Stop the current trace recording session.
     *
     * Finalizes the .rbt file (checkpoint + segment end).
     */
    public function stop(): void
    {
        if ($this->output === null) {
            return;
        }
        $this->output->finish();
        $this->output = null;
    }

    public function isActive(): bool
    {
        return $this->output !== null;
    }

    /**
     * Path of the current (or most recently completed) trace file.
     */
    public function getCurrentPath(): ?string
    {
        return $this->current_path;
    }
}
