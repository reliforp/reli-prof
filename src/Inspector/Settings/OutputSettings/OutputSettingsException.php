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

namespace Reli\Inspector\Settings\OutputSettings;

use Reli\Inspector\Settings\InspectorSettingsException;

class OutputSettingsException extends InspectorSettingsException
{
    public const OUTPUT_IS_NOT_STRING = 1;
    public const TEMPLATE_NOT_SPECIFIED = 2;

    protected const ERRORS = [
        self::OUTPUT_IS_NOT_STRING => 'output must be a string',
        self::TEMPLATE_NOT_SPECIFIED => 'template is not specified',
    ];
}
