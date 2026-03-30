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

namespace Reli\Inspector\Watch\Action;

use PHPUnit\Framework\TestCase;
use Reli\Inspector\Watch\HeapStats;
use Reli\Inspector\Watch\TriggerEvent;
use Reli\Inspector\Watch\WatchContext;
use Reli\Lib\Process\ProcessSpecifier;

class LogActionTest extends TestCase
{
    public function testLogOutputFormat(): void
    {
        $stream = fopen('php://memory', 'rw');
        $this->assertNotFalse($stream);

        $action = new LogAction($stream);
        $this->assertSame('log', $action->name());

        $event = new TriggerEvent(
            trigger_name: 'memory-usage',
            description: 'mem=10M>5M',
            timestamp: 1711684800.0,
        );
        $process = new ProcessSpecifier(42);
        $context = new WatchContext(
            pid: 42,
            heap_stats: new HeapStats(
                10 * 1024 * 1024,
                16 * 1024 * 1024,
                12 * 1024 * 1024,
                128 * 1024 * 1024,
            ),
            call_trace: null,
            timestamp: 1711684800.0,
            previous: null,
        );

        $action->execute($event, $process, $context);

        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);

        $this->assertStringContainsString('PID=42', $output);
        $this->assertStringContainsString('trigger=memory-usage', $output);
        $this->assertStringContainsString('mem=10M>5M', $output);
        $this->assertStringContainsString('mem=10.0M', $output);
    }

    public function testToFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'reli_log_test_');
        $this->assertNotFalse($path);

        $action = LogAction::toFile($path);
        $event = new TriggerEvent(
            trigger_name: 'test',
            description: 'test event',
            timestamp: 1711684800.0,
        );
        $process = new ProcessSpecifier(1);
        $context = new WatchContext(
            pid: 1,
            heap_stats: new HeapStats(0, 0, 0, 0),
            call_trace: null,
            timestamp: 1711684800.0,
            previous: null,
        );

        $action->execute($event, $process, $context);

        $content = file_get_contents($path);
        $this->assertNotFalse($content);
        $this->assertStringContainsString('trigger=test', $content);

        unlink($path);
    }
}
