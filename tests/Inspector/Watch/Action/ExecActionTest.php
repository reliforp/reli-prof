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

class ExecActionTest extends TestCase
{
    public function testName(): void
    {
        $action = new ExecAction('echo test');
        $this->assertSame('exec', $action->name());
    }

    public function testExecutesCommandWithEnvVars(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'reli_exec_test_');
        $this->assertNotFalse($tmpFile);

        $command = 'echo "PID=$RELI_WATCH_PID '
            . 'TRIGGER=$RELI_WATCH_TRIGGER" > ' . escapeshellarg($tmpFile);

        $action = new ExecAction($command);
        $event = new TriggerEvent(
            'memory-usage',
            'mem=10M>5M',
            1711684800.0,
        );
        $context = new WatchContext(
            pid: 42,
            heap_stats: new HeapStats(
                10 * 1024 * 1024,
                16 * 1024 * 1024,
                12 * 1024 * 1024,
                0,
            ),
            call_trace: null,
            timestamp: 1711684800.0,
            previous: null,
        );

        $action->execute($event, new ProcessSpecifier(42), $context);

        // Give subprocess time to write
        usleep(100000);

        $output = file_get_contents($tmpFile);
        $this->assertNotFalse($output);
        $this->assertStringContainsString('PID=42', $output);
        $this->assertStringContainsString('TRIGGER=memory-usage', $output);

        unlink($tmpFile);
    }
}
