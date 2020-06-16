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

final class GetTraceSettingsException extends CommandSettingsException
{
    public const DEPTH_IS_NOT_INTEGER = 1;

    protected const ERRORS = [
        self::DEPTH_IS_NOT_INTEGER => 'depth is not integer',
    ];
}
