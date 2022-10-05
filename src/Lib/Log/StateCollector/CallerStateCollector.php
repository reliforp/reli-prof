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

namespace PhpProfiler\Lib\Log\StateCollector;

use PhpProfiler\Lib\Log\Log;

use function debug_backtrace;

final class CallerStateCollector implements StateCollector
{
    public function collect(): array
    {
        $backtrace = debug_backtrace(limit: 5);

        $last_logger_frame = [];
        $caller_frame = [];

        foreach ($backtrace as $frame) {
            if ($this->isLoggerFrame($frame)) {
                $last_logger_frame = $frame;
            } elseif ($last_logger_frame) {
                $caller_frame = $frame;
                break;
            }
        }

        $result = [];
        if ($last_logger_frame) {
            assert(isset($last_logger_frame['file']));
            /** @psalm-suppress RedundantCondition */
            assert(isset($last_logger_frame['line']));
            $result['context'] = [
                'file' => $last_logger_frame['file'],
                'line' => $last_logger_frame['line'],
                'class' => $caller_frame['class'] ?? '',
                'function' => $caller_frame['function'] ?? '',
                'args' => $caller_frame['args'] ?? '',
            ];
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
