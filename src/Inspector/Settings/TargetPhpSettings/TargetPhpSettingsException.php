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

namespace Reli\Inspector\Settings\TargetPhpSettings;

use Reli\Inspector\Settings\InspectorSettingsException;

final class TargetPhpSettingsException extends InspectorSettingsException
{
    public const PHP_REGEX_IS_NOT_STRING = 3;
    public const LIBPTHREAD_REGEX_IS_NOT_STRING = 4;
    public const ZTS_GLOBALS_REGEX_IS_NOT_STRING = 5;
    public const PHP_PATH_IS_NOT_STRING = 6;
    public const LIBPTHREAD_PATH_IS_NOT_STRING = 7;
    public const TARGET_PHP_VERSION_INVALID = 8;

    protected const ERRORS = [
        self::PHP_REGEX_IS_NOT_STRING => 'php-regex must be a string',
        self::LIBPTHREAD_REGEX_IS_NOT_STRING => 'libpthread-regex must be a string',
        self::ZTS_GLOBALS_REGEX_IS_NOT_STRING => 'zts-globals-regex must be a string',
        self::PHP_PATH_IS_NOT_STRING => 'php-path must be a string',
        self::LIBPTHREAD_PATH_IS_NOT_STRING => 'libpthread-path must be a string',
        self::TARGET_PHP_VERSION_INVALID => 'php-version must be valid version string (eg: v80)',
    ];
}
