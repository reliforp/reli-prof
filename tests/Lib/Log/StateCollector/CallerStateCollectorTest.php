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

namespace Reli\Lib\Log\StateCollector;

use Reli\Lib\Log\Log;
use Reli\Lib\Log\TestLoggerTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CallerStateCollectorTest extends TestCase
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
    }

    public function testCollect(): void
    {
        Log::initializeLogger(
            new class ($this) implements LoggerInterface {
                use TestLoggerTrait;

                public function log($level, $message, array $context = [])
                {
                    $this->test_case->assertSame(__FILE__, $context['context']['file']);
                    $this->test_case->assertSame(56, $context['context']['line']);
                    $this->test_case->assertSame('testCollect', $context['context']['function']);
                    $this->test_case->assertSame(CallerStateCollectorTest::class, $context['context']['class']);
                }
            },
            new CallerStateCollector()
        );
        Log::info('');
    }
}
