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

namespace PhpProfiler\Lib\Log\StateCollector;

use PhpProfiler\Lib\Log\Log;

use function debug_backtrace;

final class CallerStateCollector implements StateCollector
{
    public function collect(): array
    {
        $result = [];
        $backtrace = debug_backtrace(limit: 5);
        $in_logger_frame = false;
        $previous_frame = [];
        foreach ($backtrace as $frame) {
            if ($this->isLoggerFrame($frame)) {
                $in_logger_frame = true;
            } elseif ($in_logger_frame) {
                $result['context'] = [
                    'file' => $previous_frame['file'],
                    'line' => $previous_frame['line'],
                    'class' => $frame['class'],
                    'function' => $frame['function'],
                    'args' => $frame['args'],
                ];
                break;
            }
            $previous_frame = $frame;
        }
        return $result;
    }

    private function isLoggerFrame(array $frame): bool
    {
        if ($frame['class'] === Log::class) {
            if (in_array($frame['function'], ['log', ...Log::LOG_LEVELS], true)) {
                return true;
            }
        }
        return false;
    }
}
