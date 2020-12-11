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

namespace PhpProfiler\Inspector\Daemon\Dispatcher\Protocol\Message;

use PhpProfiler\Inspector\Settings\DaemonSettings\DaemonSettings;
use PhpProfiler\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use PhpProfiler\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use PhpProfiler\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;

final class SettingsMessage
{
    public GetTraceSettings $get_trace_settings;
    public DaemonSettings $daemon_settings;
    public TargetPhpSettings $target_php_settings;
    public TraceLoopSettings $trace_loop_settings;

    public function __construct(
        GetTraceSettings $get_trace_settings,
        DaemonSettings $daemon_settings,
        TargetPhpSettings $target_php_settings,
        TraceLoopSettings $trace_loop_settings
    ) {
        $this->trace_loop_settings = $trace_loop_settings;
        $this->target_php_settings = $target_php_settings;
        $this->daemon_settings = $daemon_settings;
        $this->get_trace_settings = $get_trace_settings;
    }
}