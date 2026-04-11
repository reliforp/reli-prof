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

namespace Reli\Inspector\Output\TraceOutput;

use Reli\Converter\BinaryTrace\BinaryTraceWriter;
use Reli\Converter\BinaryTrace\CallTraceConverter;
use Reli\Converter\ParsedCallTrace;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;
use Reli\Lib\PhpProcessReader\CallTraceReader\MergedCallTrace;

final class BinaryTraceOutput implements TraceOutput, MergedTraceOutput
{
    private bool $header_written = false;
    private ?int $last_hrtime_ns = null;

    /**
     * @param resource|null $write_buffer The buffer the writer writes to
     *        (only needed when compress_to is set, so we can read it back).
     * @param resource|null $compress_to When set, the write_buffer is
     *        gzip-compressed and written to this stream on finish().
     */
    public function __construct(
        private BinaryTraceWriter $writer,
        private int $checkpoint_interval = 1000,
        private $write_buffer = null,
        private $compress_to = null,
    ) {
    }

    #[\Override]
    public function output(CallTrace $call_trace, ?array $annotations = null): void
    {
        $this->writeParsed(CallTraceConverter::toParsed($call_trace), $annotations);
    }

    #[\Override]
    public function outputMerged(MergedCallTrace $merged_trace, ?array $annotations = null): void
    {
        $this->writeParsed(CallTraceConverter::mergedToParsed($merged_trace), $annotations);
    }

    /**
     * Finalize: write checkpoint + segment end, then gzip if configured.
     */
    public function finish(): void
    {
        if ($this->header_written) {
            $this->writer->writeCheckpoint();
            $this->writer->writeSegmentEnd();
        }

        if (
            $this->compress_to !== null
            && $this->write_buffer !== null
            && $this->header_written
        ) {
            $buf = $this->write_buffer;
            $this->write_buffer = null;
            rewind($buf);
            $raw = stream_get_contents($buf);
            fclose($buf);

            if ($raw !== false && $raw !== '') {
                $compressed = gzencode($raw);
                if ($compressed !== false) {
                    fwrite($this->compress_to, $compressed);
                }
            }
        }
    }

    /**
     * @param array<string, string>|null $annotations
     */
    private function writeParsed(ParsedCallTrace $parsed, ?array $annotations = null): void
    {
        if (!$this->header_written) {
            $this->writer->writeHeader();
            $this->header_written = true;
        }

        $now_ns = hrtime(true);
        $delta_us = 0;
        if ($this->last_hrtime_ns !== null) {
            $delta_us = (int)(($now_ns - $this->last_hrtime_ns) / 1000);
        }
        $this->last_hrtime_ns = $now_ns;

        $this->writer->writeTrace($parsed, $delta_us, $annotations);

        if ($this->writer->getSamplesSinceCheckpoint() >= $this->checkpoint_interval) {
            $this->writer->writeCheckpoint();
        }
    }
}
