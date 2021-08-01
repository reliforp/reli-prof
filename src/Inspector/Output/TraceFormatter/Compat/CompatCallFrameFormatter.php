<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Inspector\Output\TraceFormatter\Compat;

use PhpProfiler\Lib\PhpProcessReader\CallFrame;

final class CompatCallFrameFormatter
{
    public function format(CallFrame $call_frame): string
    {
        return "{$this->formatFunctionName($call_frame)} {$call_frame->file_name}({$this->formatOpLine($call_frame)})";
    }

    private function formatFunctionName(CallFrame $call_frame): string
    {
        if ($call_frame->class_name === '') {
            return $call_frame->function_name;
        }
        return "{$call_frame->class_name}::{$call_frame->function_name}";
    }

    private function formatOpLine(CallFrame $call_frame): string
    {
        if (is_null($call_frame->opline)) {
            return '-1';
        }
        return "{$call_frame->opline->lineno}";
    }
}
