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

use Reli\Lib\Log\StateCollector\StateCollector;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class LogTest extends TestCase
{
    private LoggerInterface $logger_backup;
    private StateCollector $state_collector_backup;

    public function setUp(): void
    {
        $this->logger_backup = Log::getLogger();
        $this->state_collector_backup = Log::getCollector();
    }

    public function tearDown(): void
    {
        Log::initializeLogger(
            $this->logger_backup,
            $this->state_collector_backup,
        );
        if ($container = \Mockery::getContainer()) {
            $this->addToAssertionCount($container->mockery_getExpectationCount());
        }
    }

    /**
     * @dataProvider logLevelsProvider
     */
    public function testInfo(string $log_level, string $method): void
    {
        $logger = \Mockery::mock(LoggerInterface::class);
        $collector = \Mockery::mock(StateCollector::class);

        $logger->expects()->log($log_level, 'test', ['test']);
        $collector->expects()->collect()->andReturn(['test']);
        Log::initializeLogger(
            $logger,
            $collector
        );
        Log::$method('test');
    }

    public function logLevelsProvider(): array
    {
        return [
            'emergency' => [LogLevel::EMERGENCY, 'emergency'],
            'alert' => [LogLevel::ALERT, 'alert'],
            'critical' => [LogLevel::CRITICAL, 'critical'],
            'error' => [LogLevel::ERROR, 'error'],
            'warning' => [LogLevel::WARNING, 'warning'],
            'notice' => [LogLevel::NOTICE, 'notice'],
            'info' => [LogLevel::INFO, 'info'],
            'debug' => [LogLevel::DEBUG, 'debug'],
        ];
    }
}
