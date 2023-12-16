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

namespace Reli\Inspector\Settings\MemoryProfilerSettings;

use Reli\Inspector\Settings\InspectorSettingsException;

class MemoryProfilerSettingsException extends InspectorSettingsException
{
    public const MEMORY_LIMIT_ERROR_MAX_DEPTH_IS_NOT_POSITIVE_INTEGER = 1;
    public const MEMORY_LIMIT_ERROR_LINE_IS_NOT_INTEGER = 2;

    protected const ERRORS = [
        self::MEMORY_LIMIT_ERROR_MAX_DEPTH_IS_NOT_POSITIVE_INTEGER
            => 'memory_limit_error_max_depth is not positive integer',
        self::MEMORY_LIMIT_ERROR_LINE_IS_NOT_INTEGER
            => 'memory_limit_error_line is not integer',
    ];
}
