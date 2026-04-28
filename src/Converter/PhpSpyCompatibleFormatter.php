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

namespace Reli\Converter;

/**
 * Formats {@see ParsedCallTrace} as phpspy-compatible text.
 *
 * Each frame is written as `"<depth> <function_name> <file_name>:<lineno>"`
 * on its own line; traces are separated by a single blank line, matching
 * the format produced by phpspy and consumed by both
 * {@see PhpSpyCompatibleParser} and the upstream `stackcollapse-phpspy.pl`.
 */
final class PhpSpyCompatibleFormatter
{
    public function formatTrace(ParsedCallTrace $trace): string
    {
        $out = '';
        foreach ($trace->call_frames as $depth => $frame) {
            $out .= $depth . ' '
                . $frame->function_name . ' '
                . $frame->file_name . ':' . $frame->lineno
                . "\n";
        }
        $out .= "\n";
        return $out;
    }

    /**
     * @param resource $stream
     * @param iterable<ParsedCallTrace> $traces
     */
    public function writeTracesTo($stream, iterable $traces): void
    {
        foreach ($traces as $trace) {
            fwrite($stream, $this->formatTrace($trace));
        }
    }
}
