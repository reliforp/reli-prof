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
use Reli\Converter\ParsedCallFrame;
use Reli\Converter\ParsedCallTrace;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;

final class BinaryTraceOutput implements TraceOutput
{
    private bool $header_written = false;
    private int $checkpoint_interval;

    public function __construct(
        private BinaryTraceWriter $writer,
        int $checkpoint_interval = 1000,
    ) {
        $this->checkpoint_interval = $checkpoint_interval;
    }

    #[\Override]
    public function output(CallTrace $call_trace): void
    {
        if (!$this->header_written) {
            $this->writer->writeHeader();
            $this->header_written = true;
        }

        $parsed = $this->convertToParsed($call_trace);
        $this->writer->writeTrace($parsed);

        if ($this->writer->getSamplesSinceCheckpoint() >= $this->checkpoint_interval) {
            $this->writer->writeCheckpoint();
        }
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
