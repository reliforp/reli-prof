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

use PhpProfiler\Inspector\Output\TraceFormatter\CallTraceFormatter;
use PhpProfiler\Lib\PhpProcessReader\CallTrace;

final class CompatCallTraceFormatter implements CallTraceFormatter
{
    private static ?self $cache;

    public function __construct(
        private CompatCallFrameFormatter $call_frame_formatter,
    ) {
    }

    public static function getInstance(): self
    {
        if (!isset(self::$cache)) {
            self::$cache = new self(new CompatCallFrameFormatter());
        }
        return self::$cache;
    }

    public function format(CallTrace $call_trace): string
    {
        $frames = [];
        foreach ($call_trace->call_frames as $call_frame) {
            $frames[] = $this->call_frame_formatter->format($call_frame);
        }
        return join(PHP_EOL, $frames);
    }
}
