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

use Reli\Lib\PhpProcessReader\CallTraceReader\MergedCallTrace;

final class MergedCallTraceFormatter
{
    /**
     * @param array<string, string>|null $annotations optional per-sample
     *     key/value metadata, emitted as `# key = value` comment lines
     *     after the frame list (same convention as the phpspy template).
     *     reli's own PhpSpyCompatibleParser treats `#`-prefixed lines as
     *     comments, so the merged output remains round-trip safe.
     */
    public function format(MergedCallTrace $trace, ?array $annotations = null): string
    {
        $output = '';
        $depth = 0;

        foreach ($trace->frames as $frame) {
            if ($frame->isNative()) {
                $native = $frame->nativeFrame;
                assert($native !== null);
                $display = $native->getDisplayName();
                // phpspy-compatible: "<depth> <name> <file>:<line>"
                // Use [native] as pseudo-filename with line 0 for tool compatibility
                $output .= "{$depth} {$native->module_name}::{$display} [native]:0\n";
            } else {
                $php = $frame->phpFrame;
                assert($php !== null);
                $output .= "{$depth} {$php->getFullyQualifiedFunctionName()} {$php->file_name}:{$php->getLineno()}\n";
            }
            $depth++;
        }

        if ($annotations !== null && $annotations !== []) {
            foreach ($annotations as $key => $value) {
                $output .= "# {$key} = {$value}\n";
            }
        }

        $output .= "\n";
        return $output;
    }
}
