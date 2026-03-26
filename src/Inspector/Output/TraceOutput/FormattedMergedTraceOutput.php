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

use Reli\Inspector\Output\OutputChannel\OutputChannel;
use Reli\Inspector\Output\TraceFormatter\MergedCallTraceFormatter;
use Reli\Lib\PhpProcessReader\CallTraceReader\MergedCallTrace;

final class FormattedMergedTraceOutput implements MergedTraceOutput
{
    public function __construct(
        private OutputChannel $outputChannel,
        private MergedCallTraceFormatter $formatter,
    ) {
    }

    #[\Override]
    public function outputMerged(MergedCallTrace $merged_trace): void
    {
        $this->outputChannel->output(
            $this->formatter->format($merged_trace)
        );
    }
}
