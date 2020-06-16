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

final class TargetProcessSettingsException extends CommandSettingsException
{
    public const PID_NOT_SPECIFIED = 1;
    public const PID_IS_NOT_INTEGER = 2;

    protected const ERRORS = [
        self::PID_NOT_SPECIFIED => 'pid is not specified',
        self::PID_IS_NOT_INTEGER => 'pid is not integer',
    ];
}
