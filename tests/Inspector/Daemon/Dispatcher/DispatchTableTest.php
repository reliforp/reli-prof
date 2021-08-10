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

namespace PhpProfiler\Inspector\Daemon\Dispatcher;

use PhpProfiler\Inspector\Daemon\Reader\Controller\PhpReaderControllerInterface;
use PhpProfiler\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use PhpProfiler\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use PhpProfiler\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;
use PHPUnit\Framework\TestCase;

class DispatchTableTest extends TestCase
{
    public function testUpdateTarget()
    {
        $attached = [];
        $worker1 = \Mockery::mock(PhpReaderControllerInterface::class);
        $worker1->expects()
            ->sendAttach()
            ->withArgs(function (int $pid) use (&$attached) {
                $attached[] = $pid;
                return true;
            });
        $worker2 = clone $worker1;
        $worker3 = clone $worker1;
        $workers = [$worker1, $worker2, $worker3];
        $worker_pool = \Mockery::mock(WorkerPoolInterface::class);
        $dispatch_table = new DispatchTable(
            $worker_pool,
            new TargetPhpSettings(),
            new TraceLoopSettings(1, 'test', 1, false),
            new GetTraceSettings(1)
        );

        $worker_pool->expects()->getFreeWorker()->andReturns($worker1);
        $worker_pool->expects()->getFreeWorker()->andReturns($worker2);
        $worker_pool->expects()->getFreeWorker()->andReturns($worker3);
        $worker_pool->expects()->getFreeWorker()->andReturns(null);
        $dispatch_table->updateTargets(new TargetProcessList(1, 2, 3));
        $attached_first = $attached;
        sort($attached);
        $this->assertSame([1, 2, 3], $attached);

        $attached = [];
        $detached = $workers[array_search(3, $attached_first, true)];
        $worker_pool->expects()->returnWorkerToPool($detached);
        $worker_pool->expects()->getFreeWorker()->andReturns($detached);
        $worker_pool->expects()->returnWorkerToPool($detached);
        $dispatch_table->updateTargets(new TargetProcessList(1, 2));
        $this->assertSame([], $attached);
    }
}
