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
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;

final class BinaryTraceOutput implements TraceOutput
{
    private bool $header_written = false;
    private ?int $last_hrtime_ns = null;

    public function __construct(
        private BinaryTraceWriter $writer,
        private int $checkpoint_interval = 1000,
    ) {
    }

    #[\Override]
    public function output(CallTrace $call_trace): void
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

        $this->writer->writeTrace(CallTraceConverter::toParsed($call_trace), $delta_us);

        if ($this->writer->getSamplesSinceCheckpoint() >= $this->checkpoint_interval) {
            $this->writer->writeCheckpoint();
        }
    }
}
