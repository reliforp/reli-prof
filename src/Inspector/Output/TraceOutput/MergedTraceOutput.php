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

use Reli\Lib\PhpProcessReader\CallTraceReader\MergedCallTrace;

interface MergedTraceOutput
{
    /**
     * @param array<string, string>|null $annotations optional per-sample
     *     key/value metadata (e.g. --trace-var peek results). Same
     *     semantics as TraceOutput::output(). Implementations that can't
     *     express annotations MUST silently ignore this argument.
     */
    public function outputMerged(MergedCallTrace $merged_trace, ?array $annotations = null): void;
}
