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

namespace Reli\Inspector\Settings\TargetProcessSettings;

use Reli\Inspector\Settings\InspectorSettingsException;

final class TargetProcessSettingsException extends InspectorSettingsException
{
    public const TARGET_NOT_SPECIFIED = 1;
    public const PID_IS_NOT_INTEGER = 2;

    protected const ERRORS = [
        self::TARGET_NOT_SPECIFIED => 'either pid or command must be specified',
        self::PID_IS_NOT_INTEGER => 'pid is not integer',
    ];
}
