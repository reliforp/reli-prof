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

namespace Reli\Inspector\Output\TraceFormatter;

use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;

interface CallTraceFormatter
{
    /**
     * @param array<string, string>|null $annotations optional per-sample
     *     key/value metadata (e.g. peeked variable values). Text-oriented
     *     formatters should emit these on lines that won't be mistaken for
     *     stack frames; formatters that can't express annotations MUST
     *     silently ignore the argument.
     */
    public function format(CallTrace $call_trace, ?array $annotations = null): string;
}
