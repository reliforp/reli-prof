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

namespace Reli\Inspector;

use Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;
use Reli\Lib\Loop\Loop;
use Reli\Lib\Loop\LoopBuilder;
use Reli\Lib\Loop\LoopMiddleware\CallableMiddleware;
use Reli\Lib\Loop\LoopMiddlewareInterface;
use Reli\Lib\Process\MemoryReader\MemoryReaderException;
use Reli\Lib\Process\ProcessNotFoundException;

use function hrtime;
use function time_nanosleep;

/**
 * A flat trace loop that inlines all middleware behavior into a single loop,
 * eliminating 6 levels of virtual dispatch per iteration.
 */
final class FlatTraceLoop implements LoopMiddlewareInterface
{
    /** @var resource|null */
    private $keyboard_input = null;

    public function __construct(
        private readonly \Closure $main,
        private readonly TraceLoopSettings $settings,
    ) {
    }

    #[\Override]
    public function invoke(): bool
    {
        $sleep_ns = $this->settings->sleep_nano_seconds;
        $max_retries = $this->settings->max_retries;
        $cancel_key = $this->settings->cancel_key;
        $main = $this->main;
        $retry_count = 0;

        // Keyboard setup (non-blocking stdin)
        $stdin = @fopen('php://stdin', 'r');
        if ($stdin !== false) {
            stream_set_blocking($stdin, false);
            $this->keyboard_input = $stdin;
        }

        while (true) {
            $start = hrtime(true);

            // Keyboard cancel check
            if ($this->keyboard_input !== null) {
                $key = fread($this->keyboard_input, 1);
                if ($key === $cancel_key) {
                    break;
                }
            }

            // Main work with retry + exception handling
            try {
                $result = $main();
                $retry_count = 0;
                if (!$result) {
                    break;
                }
            } catch (ProcessNotFoundException) {
                break;
            } catch (MemoryReaderException) {
                $retry_count++;
                if ($retry_count > $max_retries) {
                    break;
                }
                continue;
            }

            // NanoSleep for remaining interval
            $elapsed = hrtime(true) - $start;
            $wait = $sleep_ns - $elapsed;
            if ($wait > 0) {
                /** @psalm-suppress UnusedFunctionCall */
                time_nanosleep(0, (int)$wait);
            }
        }

        return true;
    }
}
