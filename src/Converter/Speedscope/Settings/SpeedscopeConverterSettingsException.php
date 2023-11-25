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

namespace Reli\Converter\Speedscope\Settings;

use Reli\Inspector\Settings\InspectorSettingsException;

final class SpeedscopeConverterSettingsException extends InspectorSettingsException
{
    public const UNSUPPORTED_UTF8_ERROR_HANDLING = 1;

    protected const ERRORS = [
        self::UNSUPPORTED_UTF8_ERROR_HANDLING => 'unsupported utf8 error handling type is specified',
    ];
}
