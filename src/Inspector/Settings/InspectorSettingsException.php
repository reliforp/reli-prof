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

namespace PhpProfiler\Inspector\Settings;

use LogicException;

abstract class InspectorSettingsException extends \Exception
{
    public const ERROR_NONE = 0;

    /** @var array<int, string> */
    protected const ERRORS = [
        self::ERROR_NONE => '',
    ];

    /**
     * @return array<int, string>
     */
    public static function getErrors(): array
    {
        /** @var array<int, string> */
        return static::ERRORS;
    }

    /**
     * @param int $error_no
     * @return static
     */
    public static function create(int $error_no): InspectorSettingsException
    {
        if (!isset(static::ERRORS[$error_no])) {
            throw new LogicException('wrong creation of CommandSettingException');
        }
        return new static(static::getErrors()[$error_no], $error_no);
    }
}
