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

namespace Reli\Lib\Log;

use Reli\Lib\Log\StateCollector\NullStateCollector;
use Reli\Lib\Log\StateCollector\StateCollector;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;

final class Log
{
    public const LOG_LEVELS = [
        LogLevel::EMERGENCY,
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        LogLevel::ERROR,
        LogLevel::WARNING,
        LogLevel::NOTICE,
        LogLevel::INFO,
        LogLevel::DEBUG,
    ];

    private static ?LoggerInterface $logger = null;
    private static ?StateCollector $state_collector = null;

    public static function initializeLogger(
        LoggerInterface $logger,
        StateCollector $state_collector,
    ): void {
        self::$logger = $logger;
        self::$state_collector = $state_collector;
    }

    public static function getLogger(): LoggerInterface
    {
        if (!isset(self::$logger)) {
            self::$logger = new NullLogger();
        }
        return self::$logger;
    }

    public static function getCollector(): StateCollector
    {
        if (!isset(self::$state_collector)) {
            self::$state_collector = new NullStateCollector();
        }
        return self::$state_collector;
    }

    /**
     * @param value-of<self::LOG_LEVELS> $level
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        self::getLogger()->log($level, $message, $context + self::getCollector()->collect());
    }

    public static function emergency(string $message, array $context = []): void
    {
        self::log(LogLevel::EMERGENCY, $message, $context);
    }

    public static function alert(string $message, array $context = []): void
    {
        self::log(LogLevel::ALERT, $message, $context);
    }

    public static function critical(string $message, array $context = []): void
    {
        self::log(LogLevel::CRITICAL, $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log(LogLevel::ERROR, $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log(LogLevel::WARNING, $message, $context);
    }

    public static function notice(string $message, array $context = []): void
    {
        self::log(LogLevel::NOTICE, $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log(LogLevel::INFO, $message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        self::log(LogLevel::DEBUG, $message, $context);
    }
}
