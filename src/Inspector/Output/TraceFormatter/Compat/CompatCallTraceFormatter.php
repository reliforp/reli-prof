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

namespace Reli\Inspector\Output\TraceFormatter\Compat;

use Reli\Inspector\Output\TraceFormatter\CallTraceFormatter;
use Reli\Lib\PhpProcessReader\CallTrace;

use function join;

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
