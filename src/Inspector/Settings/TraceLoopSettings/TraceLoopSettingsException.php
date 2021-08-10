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

namespace PhpProfiler\Inspector\Settings\TraceLoopSettings;

use PhpProfiler\Inspector\Settings\InspectorSettingsException;

final class TraceLoopSettingsException extends InspectorSettingsException
{
    public const SLEEP_NS_IS_NOT_INTEGER = 1;
    public const MAX_RETRY_IS_NOT_INTEGER = 2;
    public const STOP_PROCESS_IS_NOT_BOOLEAN = 3;

    protected const ERRORS = [
        self::SLEEP_NS_IS_NOT_INTEGER => 'sleep-ns is not integer',
        self::MAX_RETRY_IS_NOT_INTEGER => 'max-retries is not integer',
        self::STOP_PROCESS_IS_NOT_BOOLEAN => 'stop-process is not boolean',
    ];
}
