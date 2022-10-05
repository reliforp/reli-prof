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

namespace PhpProfiler\Inspector\Settings\DaemonSettings;

use PhpProfiler\Inspector\Settings\InspectorSettingsException;

final class DaemonSettingsException extends InspectorSettingsException
{
    public const THREADS_IS_NOT_INTEGER = 1;
    public const TARGET_REGEX_IS_NOT_STRING = 2;
    public const TARGET_REGEX_IS_NOT_SPECIFIED = 3;

    protected const ERRORS = [
        self::THREADS_IS_NOT_INTEGER => 'threads is not integer',
        self::TARGET_REGEX_IS_NOT_STRING => 'target-regex is not string',
        self::TARGET_REGEX_IS_NOT_SPECIFIED => 'target-regex is not specified',
    ];
}
