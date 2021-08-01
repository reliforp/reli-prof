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

namespace PhpProfiler\Inspector\Settings\TemplatedTraceFormatterSettings;

use PhpProfiler\Inspector\Settings\InspectorSettingsException;

final class TemplateSettingsException extends InspectorSettingsException
{
    public const TEMPLATE_NOT_SPECIFIED = 1;

    protected const ERRORS = [
        self::TEMPLATE_NOT_SPECIFIED => 'template is not specified',
    ];
}
