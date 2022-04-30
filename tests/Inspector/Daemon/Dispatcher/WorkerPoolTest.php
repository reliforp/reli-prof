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
use PhpProfiler\Inspector\Daemon\Reader\Controller\PhpReaderControllerInterface;
use PhpProfiler\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use PhpProfiler\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;
use PHPUnit\Framework\TestCase;

class WorkerPoolTest extends TestCase
{
    public function testCreate()
    {
        $trace_settings = new TraceLoopSettings(1, 'q', 1, false);
        $get_trace_settings = new GetTraceSettings(1);

        $reader_context = Mockery::mock(PhpReaderControllerInterface::class);
        $reader_context->expects()
            ->start()
            ->andReturns(new Success(null));
        $reader_context->expects()
            ->sendSettings(
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
            $trace_settings,
            $get_trace_settings
        );
        $this->assertInstanceOf(WorkerPool::class, $worker_pool);
    }

    public function testGetFreeWorker()
    {
        $reader_context1 = Mockery::mock(PhpReaderControllerInterface::class);
        $reader_context2 = Mockery::mock(PhpReaderControllerInterface::class);
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

    public function testGetWorkers()
    {
        $reader_context1 = Mockery::mock(PhpReaderControllerInterface::class);
        $reader_context2 = Mockery::mock(PhpReaderControllerInterface::class);
        $worker_pool = new WorkerPool(
            $reader_context1,
            $reader_context2
        );
        $workers = [];
        foreach ($worker_pool->getWorkers() as $worker) {
            $workers[] = $worker;
        }
        $this->assertSame(
            [
                $reader_context1,
                $reader_context2
            ],
            $workers
        );
    }

    public function testReturnWorkerToPool()
    {
        $reader_context = Mockery::mock(PhpReaderControllerInterface::class);
        $worker_pool = new WorkerPool(
            $reader_context
        );
        $returned_worker = $worker_pool->getFreeWorker();
        $this->assertSame($reader_context, $returned_worker);
        $this->assertNull($worker_pool->getFreeWorker());
        $worker_pool->returnWorkerToPool($returned_worker);
        $this->assertSame($reader_context, $worker_pool->getFreeWorker());
    }
}
