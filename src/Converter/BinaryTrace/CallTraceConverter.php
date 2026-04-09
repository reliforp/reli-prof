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

use Reli\Converter\ParsedCallFrame;
use Reli\Converter\ParsedCallTrace;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;

final class CallTraceConverter
{
    public static function toParsed(CallTrace $call_trace): ParsedCallTrace
    {
        $frames = [];
        foreach ($call_trace->call_frames as $call_frame) {
            $frames[] = new ParsedCallFrame(
                $call_frame->getFullyQualifiedFunctionName(),
                $call_frame->file_name,
                // getLineno() returns -1 when opline is null (line unknown).
                // Varint encoding requires unsigned values, so clamp to 0.
                max(0, $call_frame->getLineno()),
            );
        }
        return new ParsedCallTrace(...$frames);
    }
}
