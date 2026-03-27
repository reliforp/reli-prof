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

namespace Reli\Inspector\Settings\PhpSpySettings;

use Reli\Inspector\Settings\InspectorSettingsException;

final class PhpSpySettingsException extends InspectorSettingsException
{
    public const SLEEP_US_IS_NOT_INTEGER = 1;
    public const BUFFER_SIZE_IS_NOT_INTEGER = 2;
    public const RATE_HZ_IS_NOT_INTEGER = 3;

    protected const ERRORS = [
        self::SLEEP_US_IS_NOT_INTEGER => 'sleep-us is not integer',
        self::BUFFER_SIZE_IS_NOT_INTEGER => 'buffer-size is not integer',
        self::RATE_HZ_IS_NOT_INTEGER => 'rate-hz is not integer',
    ];
}
