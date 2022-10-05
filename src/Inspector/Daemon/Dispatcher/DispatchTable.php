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

namespace Reli\Inspector\Daemon\Dispatcher;

use Reli\Inspector\Daemon\Reader\Controller\PhpReaderControllerInterface;

use function is_null;

final class DispatchTable
{
    private TargetProcessListInterface $assigned;
    /** @var array<int, PhpReaderControllerInterface> */
    private array $dispatch_table = [];

    public function __construct(
        public WorkerPoolInterface $worker_pool,
    ) {
        $this->assigned = new TargetProcessList();
    }

    public function updateTargets(TargetProcessListInterface $update): \Generator
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
            $this->dispatch_table[$picked->pid] = $worker;
            yield $worker->sendAttach($picked);
        }
    }

    public function release(TargetProcessListInterface $targets): void
    {
        foreach ($targets->getArray() as $pid) {
            $this->releaseOne($pid->pid);
        }
    }

    public function releaseOne(int $pid): void
    {
        if (isset($this->dispatch_table[$pid])) {
            $worker = $this->dispatch_table[$pid];
            $this->worker_pool->returnWorkerToPool($worker);
            unset($this->dispatch_table[$pid]);
        }
        $this->assigned->removeByPid($pid);
    }
}
