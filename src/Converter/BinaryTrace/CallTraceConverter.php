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
use Reli\Lib\PhpProcessReader\CallTraceReader\MergedCallTrace;

final class CallTraceConverter
{
    public static function toParsed(CallTrace $call_trace): ParsedCallTrace
    {
        $frames = [];
        foreach ($call_trace->call_frames as $call_frame) {
            $opcode = $call_frame->getOpcodeName();
            $frames[] = new ParsedCallFrame(
                $call_frame->getFullyQualifiedFunctionName(),
                $call_frame->file_name,
                // getLineno() returns -1 when opline is null (line unknown).
                // Varint encoding requires unsigned values, so clamp to 0.
                max(0, $call_frame->getLineno()),
                opcode_name: $opcode !== '' ? $opcode : null,
            );
        }
        return new ParsedCallTrace(...$frames);
    }

    public static function mergedToParsed(MergedCallTrace $merged): ParsedCallTrace
    {
        $frames = [];
        foreach ($merged->frames as $merged_frame) {
            if ($merged_frame->nativeFrame !== null) {
                $native = $merged_frame->nativeFrame;
                $frames[] = new ParsedCallFrame(
                    $native->symbol_name,
                    $native->module_name,
                    0,
                    frame_type: ParsedCallFrame::TYPE_NATIVE,
                    module_name: $native->module_name,
                    symbol_offset: $native->offset,
                );
            } elseif ($merged_frame->phpFrame !== null) {
                $php = $merged_frame->phpFrame;
                $opcode = $php->getOpcodeName();
                $frames[] = new ParsedCallFrame(
                    $php->getFullyQualifiedFunctionName(),
                    $php->file_name,
                    max(0, $php->getLineno()),
                    opcode_name: $opcode !== '' ? $opcode : null,
                );
            }
        }
        return new ParsedCallTrace(...$frames);
    }
}
