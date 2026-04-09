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

namespace Reli\Converter\BinaryTrace;

use Reli\Converter\ParsedCallTrace;

final class FoldedStacksFormatter
{
    /**
     * Convert an iterable of traces to folded stacks format.
     *
     * Each line: "frame1;frame2;frame3 count\n"
     * Frames are ordered from bottom (entry point) to top (leaf).
     *
     * @param iterable<BinaryTraceSample|ParsedCallTrace> $traces
     */
    public function format(iterable $traces): string
    {
        /** @var array<string, int> stack_string => count */
        $counts = [];

        foreach ($traces as $item) {
            $trace = $item instanceof BinaryTraceSample ? $item->trace : $item;
            $frames = [];
            // call_frames[0] is innermost (leaf), last is outermost (root)
            // folded stacks: root;...;leaf, so reverse
            foreach (array_reverse($trace->call_frames) as $frame) {
                $frames[] = $frame->function_name
                    . ' ' . $frame->file_name
                    . ':' . $frame->lineno;
            }
            $key = implode(';', $frames);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $output = '';
        foreach ($counts as $stack => $count) {
            $output .= $stack . ' ' . $count . "\n";
        }
        return $output;
    }
}
