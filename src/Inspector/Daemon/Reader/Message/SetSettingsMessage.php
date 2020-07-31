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

namespace PhpProfiler\Inspector\Daemon\Reader\Message;

use PhpProfiler\Inspector\Settings\GetTraceSettings;
use PhpProfiler\Inspector\Settings\TargetPhpSettings;
use PhpProfiler\Inspector\Settings\TraceLoopSettings;

final class SetSettingsMessage
{
    public TargetPhpSettings $target_php_settings;
    public TraceLoopSettings $trace_loop_settings;
    public GetTraceSettings $get_trace_settings;

    public function __construct(
        TargetPhpSettings $target_php_settings,
        TraceLoopSettings $trace_loop_settings,
        GetTraceSettings $get_trace_settings
    ) {
        $this->target_php_settings = $target_php_settings;
        $this->trace_loop_settings = $trace_loop_settings;
        $this->get_trace_settings = $get_trace_settings;
    }
}
