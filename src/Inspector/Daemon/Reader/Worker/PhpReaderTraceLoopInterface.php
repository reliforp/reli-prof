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

namespace PhpProfiler\Inspector\Daemon\Reader\Worker;

use Generator;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;
use PhpProfiler\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use PhpProfiler\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use PhpProfiler\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;
use PhpProfiler\Lib\PhpInternals\ZendTypeReader;
use PhpProfiler\Lib\Process\ProcessSpecifier;

interface PhpReaderTraceLoopInterface
{
    /**
     * @param TargetPhpSettings<value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS>> $target_php_settings
     * @return Generator<TraceMessage>
     */
    public function run(
        ProcessSpecifier $process_specifier,
        TraceLoopSettings $loop_settings,
        TargetPhpSettings $target_php_settings,
        GetTraceSettings $get_trace_settings
    ): Generator;
}
