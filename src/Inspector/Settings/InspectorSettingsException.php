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

namespace Reli\Inspector\Settings;

use LogicException;
use Throwable;

/** @psalm-consistent-constructor */
abstract class InspectorSettingsException extends \Exception
{
    public const ERROR_NONE = 0;

    /** @var array<int, string> */
    protected const ERRORS = [
        self::ERROR_NONE => '',
    ];

    /**
     * @param string $message
     * @param int $code
     * @param Throwable|null $previous
     */
    public function __construct($message = "", $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /** @return array<int, string> */
    public static function getErrors(): array
    {
        /** @var array<int, string> */
        return static::ERRORS;
    }

    /** @return static */
    public static function create(int $error_no): InspectorSettingsException
    {
        if (!isset(static::ERRORS[$error_no])) {
            throw new LogicException('wrong creation of CommandSettingException');
        }
        return new static(static::getErrors()[$error_no], $error_no);
    }
}
