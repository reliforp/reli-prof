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

use Mockery;
use Reli\BaseTestCase;
use Reli\Inspector\MemoryDump\MemoryDumper;
use Reli\Inspector\MemoryDump\MemoryDumpResult;
use Reli\Inspector\Watch\DiskUsageTracker;
use Reli\Inspector\Watch\HeapStats;
use Reli\Inspector\Watch\TriggerEvent;
use Reli\Inspector\Watch\WatchContext;
use Reli\Lib\Process\ProcessSpecifier;
use Reli\Lib\Process\ProcessStopper\ProcessStopper;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class DaemonMemoryDumpActionTest extends BaseTestCase
{
    public function testName(): void
    {
        $dumper = Mockery::mock(
            'overload:' . MemoryDumper::class,
        );
        $stopper = Mockery::mock('overload:' . ProcessStopper::class);
        $action = new DaemonMemoryDumpAction(
            $dumper,
            $stopper,
            '/tmp',
            new DiskUsageTracker(1024 * 1024),
        );
        $this->assertSame('memory-dump', $action->name());
    }

    public function testSkipsWhenDiskLimitReached(): void
    {
        $dumper = Mockery::mock(
            'overload:' . MemoryDumper::class,
        );
        $dumper->shouldNotReceive('dump');

        $tracker = new DiskUsageTracker(10);
        $tmp = tempnam(sys_get_temp_dir(), 'reli_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, str_repeat('x', 100));
        $tracker->recordFile($tmp);
        unlink($tmp);

        $stopper = Mockery::mock('overload:' . ProcessStopper::class);
        $action = new DaemonMemoryDumpAction(
            $dumper,
            $stopper,
            '/tmp',
            $tracker,
        );

        $action->execute(
            new TriggerEvent('test', 'desc', 100.0),
            new ProcessSpecifier(1),
            $this->makeContext(),
        );
    }

    public function testSkipsWhenMissingAddresses(): void
    {
        $dumper = Mockery::mock(
            'overload:' . MemoryDumper::class,
        );
        $dumper->shouldNotReceive('dump');

        $stopper = Mockery::mock('overload:' . ProcessStopper::class);
        $action = new DaemonMemoryDumpAction(
            $dumper,
            $stopper,
            '/tmp',
            new DiskUsageTracker(1024 * 1024),
        );

        // No daemon addresses → skip
        $action->execute(
            new TriggerEvent('test', 'desc', 100.0),
            new ProcessSpecifier(1),
            $this->makeContext(0, 0, ''),
        );
    }

    public function testExecutesDump(): void
    {
        $result = new MemoryDumpResult('/tmp/test.dump', 5, 1024);
        $dumper = Mockery::mock(
            'overload:' . MemoryDumper::class,
        );
        $dumper->shouldReceive('dump')->once()->andReturn($result);

        $stopper = Mockery::mock('overload:' . ProcessStopper::class);
        $stopper->shouldReceive('stop')->once()->andReturn(true);
        $stopper->shouldReceive('resume')->once();

        $action = new DaemonMemoryDumpAction(
            $dumper,
            $stopper,
            sys_get_temp_dir(),
            new DiskUsageTracker(1024 * 1024),
        );

        $action->execute(
            new TriggerEvent('mem', 'desc', 100.0),
            new ProcessSpecifier(42),
            $this->makeContext(0x1000, 0x2000, 'v84'),
        );
    }

    public function testPassesIncludeBinaryFlag(): void
    {
        $result = new MemoryDumpResult('/tmp/test.dump', 5, 1024);
        $dumper = Mockery::mock(
            'overload:' . MemoryDumper::class,
        );
        $dumper->shouldReceive('dump')
            ->once()
            ->withArgs(function (
                ProcessSpecifier $ps,
                $tps,
                int $eg,
                int $cg,
                string $path,
                bool $include_binary,
            ): bool {
                return $include_binary === true;
            })
            ->andReturn($result);

        $stopper = Mockery::mock('overload:' . ProcessStopper::class);
        $stopper->shouldReceive('stop')->once()->andReturn(true);
        $stopper->shouldReceive('resume')->once();

        $action = new DaemonMemoryDumpAction(
            $dumper,
            $stopper,
            sys_get_temp_dir(),
            new DiskUsageTracker(1024 * 1024),
            true,
        );

        $action->execute(
            new TriggerEvent('mem', 'desc', 100.0),
            new ProcessSpecifier(42),
            $this->makeContext(0x1000, 0x2000, 'v84'),
        );
    }

    private function makeContext(
        int $eg = 0,
        int $cg = 0,
        string $ver = '',
    ): WatchContext {
        return new WatchContext(
            pid: 1,
            heap_stats: new HeapStats(0, 0, 0, 0),
            call_trace: null,
            timestamp: 100.0,
            previous: null,
            daemon_eg_address: $eg,
            daemon_cg_address: $cg,
            daemon_php_version: $ver,
        );
    }
}
