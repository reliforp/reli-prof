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

namespace Reli\Inspector\Settings\GetTraceSettings;

use Reli\Inspector\Settings\InspectorSettingsException;

final class GetTraceSettingsException extends InspectorSettingsException
{
    public const DEPTH_IS_NOT_INTEGER = 1;
    public const BULK_STACK_COPY_IS_NOT_VALID_SIZE = 2;
    public const TRACE_VAR_EXPRESSION_IS_INVALID = 3;
    public const TRACE_VAR_EVERY_IS_NOT_POSITIVE_INTEGER = 4;

    protected const array ERRORS = [
        self::DEPTH_IS_NOT_INTEGER => 'depth is not integer',
        self::BULK_STACK_COPY_IS_NOT_VALID_SIZE =>
            'bulk-stack-copy value must be an integer with optional K/M suffix (e.g. 65536, 64K, 1M)',
        self::TRACE_VAR_EXPRESSION_IS_INVALID =>
            'trace-var expression is invalid (see --var syntax for inspector:peek-var)',
        self::TRACE_VAR_EVERY_IS_NOT_POSITIVE_INTEGER =>
            'trace-var-every must be a positive integer (1 or greater)',
    ];
}
