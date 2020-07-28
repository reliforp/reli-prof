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

use PhpProfiler\Inspector\Daemon\Reader\Context\PhpReaderContextInterface;
use PhpProfiler\Inspector\Settings\GetTraceSettings;
use PhpProfiler\Inspector\Settings\TargetPhpSettings;
use PhpProfiler\Inspector\Settings\TraceLoopSettings;

final class DispatchTable
{
    private WorkerPoolInterface $worker_pool;
    private TargetProcessListInterface $assigned;
    private TargetPhpSettings $target_php_settings;
    private TraceLoopSettings $trace_loop_settings;
    private GetTraceSettings $get_trace_settings;
    /** @var array<int, PhpReaderContextInterface> */
    private array $dispatch_table = [];

    public function __construct(
        WorkerPoolInterface $worker_pool,
        TargetPhpSettings $target_php_settings,
        TraceLoopSettings $trace_loop_settings,
        GetTraceSettings $get_trace_settings
    ) {
        $this->worker_pool = $worker_pool;
        $this->target_php_settings = $target_php_settings;
        $this->trace_loop_settings = $trace_loop_settings;
        $this->get_trace_settings = $get_trace_settings;
        $this->assigned = new TargetProcessList();
    }

    public function updateTargets(TargetProcessListInterface $update): void
    {
        $diff = $this->assigned->getDiff($update);
        $this->release($diff);
        $unassigned_new = $update->getDiff($this->assigned);
        for ($worker = $this->worker_pool->getFreeWorker(); $worker; $worker = $this->worker_pool->getFreeWorker()) {
            $picked = $unassigned_new->pickOne();
            if (is_null($picked)) {
                $this->worker_pool->returnWorkerToPool($worker);
                break;
            }
            $this->assigned->putOne($picked);
            $this->dispatch_table[$picked] = $worker;
            $worker->sendAttach($picked);
        }
    }

    public function release(TargetProcessListInterface $targets): void
    {
        foreach ($targets->getArray() as $pid) {
            $this->releaseOne($pid);
        }
    }

    public function releaseOne(int $pid): void
    {
        $worker = $this->dispatch_table[$pid];
        $this->worker_pool->returnWorkerToPool($worker);
        unset($this->dispatch_table[$pid]);
        $this->assigned = $this->assigned->getDiff(new TargetProcessList($pid));
    }
}
