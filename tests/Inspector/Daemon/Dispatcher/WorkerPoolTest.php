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

use Amp\Success;
use Mockery;
use PhpProfiler\Inspector\Daemon\Reader\Context\PhpReaderContextCreatorInterface;
use PhpProfiler\Inspector\Daemon\Reader\Context\PhpReaderContextInterface;
use PhpProfiler\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use PhpProfiler\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use PhpProfiler\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;
use PHPUnit\Framework\TestCase;

class WorkerPoolTest extends TestCase
{
    public function testCreate()
    {
        $php_settings = new TargetPhpSettings();
        $trace_settings = new TraceLoopSettings(1, 'q', 1);
        $get_trace_settings = new GetTraceSettings(1);

        $reader_context = Mockery::mock(PhpReaderContextInterface::class);
        $reader_context->expects()
            ->start()
            ->andReturns(new Success(null));
        $reader_context->expects()
            ->sendSettings(
                $php_settings,
                $trace_settings,
                $get_trace_settings
            )
            ->andReturns(new Success(1));
        $reader_context_creator = Mockery::mock(PhpReaderContextCreatorInterface::class);
        $reader_context_creator->expects()
            ->create()
            ->andReturns($reader_context);

        $worker_pool = WorkerPool::create(
            $reader_context_creator,
            1,
            $php_settings,
            $trace_settings,
            $get_trace_settings
        );
        $this->assertInstanceOf(WorkerPool::class, $worker_pool);
    }

    public function testGetFreeWorker()
    {
        $reader_context1 = Mockery::mock(PhpReaderContextInterface::class);
        $reader_context2 = Mockery::mock(PhpReaderContextInterface::class);
        $worker_pool = new WorkerPool(
            $reader_context1,
            $reader_context2
        );
        $this->assertSame(
            [
                $reader_context1,
                $reader_context2,
            ],
            [
                $worker_pool->getFreeWorker(),
                $worker_pool->getFreeWorker()
            ]
        );
        $this->assertNull($worker_pool->getFreeWorker());
    }

    public function testGetReadableWorker()
    {
        $reader_context1 = Mockery::mock(PhpReaderContextInterface::class);
        $reader_context2 = Mockery::mock(PhpReaderContextInterface::class);
        $worker_pool = new WorkerPool(
            $reader_context1,
            $reader_context2
        );

        $readable_workers = [];
        foreach ($worker_pool->getReadableWorkers() as $pid => $worker) {
            $readable_workers[$pid] = $worker;
        }
        $this->assertSame([], $readable_workers);

        $worker1 = $worker_pool->getFreeWorker();
        $readable_workers = [];
        foreach ($worker_pool->getReadableWorkers() as $pid => $worker) {
            $readable_workers[$pid] = $worker;
        }
        $this->assertCount(1, $readable_workers);
        $this->assertSame($worker1, $readable_workers[$pid]);

        $worker2 = $worker_pool->getFreeWorker();
        foreach ($worker_pool->getReadableWorkers() as $pid => $worker) {
            $readable_workers[$pid] = $worker;
        }
        $this->assertCount(2, $readable_workers);
        $this->assertSame(
            [
                $worker1,
                $worker2,
            ],
            array_values($readable_workers)
        );

        $worker_pool->returnWorkerToPool($worker2);
        $readable_workers = [];
        foreach ($worker_pool->getReadableWorkers() as $pid => $worker) {
            $readable_workers[$pid] = $worker;
        }
        $this->assertCount(1, $readable_workers);
        $this->assertSame($worker1, $readable_workers[$pid]);

        $worker_pool->setOnRead($pid);
        $readable_workers = [];
        foreach ($worker_pool->getReadableWorkers() as $pid => $worker) {
            $readable_workers[$pid] = $worker;
        }
        $this->assertSame([], $readable_workers);

        $worker_pool->releaseOnRead($pid);
        $readable_workers = [];
        foreach ($worker_pool->getReadableWorkers() as $pid => $worker) {
            $readable_workers[$pid] = $worker;
        }
        $this->assertCount(1, $readable_workers);
        $this->assertSame($worker1, $readable_workers[$pid]);
    }
}
