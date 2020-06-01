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

class TargetProcessSettingsException extends CommandSettingsException
{
    public const PID_NOT_SPECIFIED = 1;
    public const PID_IS_NOT_INTEGER = 2;
    public const PHP_REGEX_IS_NOT_STRING = 3;
    public const LIBPTHREAD_REGEX_IS_NOT_STRING = 4;
    public const PHP_PATH_IS_NOT_STRING = 5;
    public const LIBPTHREAD_PATH_IS_NOT_STRING = 6;
    public const TARGET_PHP_VERSION_INVALID = 7;

    protected const ERRORS = [
        self::PID_NOT_SPECIFIED => 'pid is not specified',
        self::PID_IS_NOT_INTEGER => 'pid is not integer',
        self::PHP_REGEX_IS_NOT_STRING => 'php-regex must be a string',
        self::LIBPTHREAD_REGEX_IS_NOT_STRING => 'libpthread-regex must be a string',
        self::PHP_PATH_IS_NOT_STRING => 'php-path must be a string',
        self::LIBPTHREAD_PATH_IS_NOT_STRING => 'libpthread-path must be a string',
        self::TARGET_PHP_VERSION_INVALID => 'php-version must be valid version string (eg: v74)',
    ];
}
