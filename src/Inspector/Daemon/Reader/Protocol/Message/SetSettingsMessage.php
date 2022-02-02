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

namespace PhpProfiler\Inspector\Daemon\Reader\Protocol\Message;

use PhpProfiler\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use PhpProfiler\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use PhpProfiler\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;
use PhpProfiler\Lib\PhpInternals\ZendTypeReader;

final class SetSettingsMessage
{
    /** @param TargetPhpSettings<value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS>|'auto'> $target_php_settings */
    public function __construct(
        public TargetPhpSettings $target_php_settings,
        public TraceLoopSettings $trace_loop_settings,
        public GetTraceSettings $get_trace_settings
    ) {
    }
}
