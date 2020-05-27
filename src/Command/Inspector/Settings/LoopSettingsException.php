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

namespace PhpProfiler\Command\Inspector\Settings;

use PhpProfiler\Command\CommandSettingsException;

class LoopSettingsException extends CommandSettingsException
{
    public const SLEEP_NS_IS_NOT_INTEGER = 1;
    public const MAX_RETRY_IS_NOT_INTEGER = 2;

    protected const ERRORS = [
        self::SLEEP_NS_IS_NOT_INTEGER => 'sleep-ns is not integer',
        self::MAX_RETRY_IS_NOT_INTEGER => 'max-retries is not integer',
    ];
}
