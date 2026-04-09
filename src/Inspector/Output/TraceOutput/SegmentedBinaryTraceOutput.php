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

use Reli\Converter\BinaryTrace\SegmentedBinaryTraceWriter;
use Reli\Converter\ParsedCallFrame;
use Reli\Converter\ParsedCallTrace;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;

final class SegmentedBinaryTraceOutput implements TraceOutput
{
    private ?int $start_hrtime_ns = null;

    public function __construct(
        private SegmentedBinaryTraceWriter $writer,
    ) {
    }

    #[\Override]
    public function output(CallTrace $call_trace): void
    {
        $now_ns = hrtime(true);
        if ($this->start_hrtime_ns === null) {
            $this->start_hrtime_ns = $now_ns;
        }

        $timestamp_us = (int)(($now_ns - $this->start_hrtime_ns) / 1000);
        $parsed = $this->convertToParsed($call_trace);
        $this->writer->writeTrace($parsed, $timestamp_us);
    }

    public function finish(): void
    {
        $this->writer->finish();
    }

    private function convertToParsed(CallTrace $call_trace): ParsedCallTrace
    {
        $frames = [];
        foreach ($call_trace->call_frames as $call_frame) {
            $frames[] = new ParsedCallFrame(
                $call_frame->getFullyQualifiedFunctionName(),
                $call_frame->file_name,
                $call_frame->getLineno(),
            );
        }
        return new ParsedCallTrace(...$frames);
    }
}
