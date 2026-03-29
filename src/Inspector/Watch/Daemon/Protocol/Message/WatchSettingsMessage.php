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

namespace Reli\Inspector\Watch\Daemon\Protocol\Message;

use Reli\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;
use Reli\Inspector\Settings\WatchSettings\WatchSettings;

final class WatchSettingsMessage
{
    public function __construct(
        public readonly WatchSettings $watch_settings,
        public readonly TraceLoopSettings $trace_loop_settings,
        public readonly GetTraceSettings $get_trace_settings,
    ) {
    }
}
