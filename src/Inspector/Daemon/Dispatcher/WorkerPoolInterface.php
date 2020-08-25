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

interface WorkerPoolInterface
{
    public function getFreeWorker(): ?PhpReaderControllerInterface;

    /**
     * @return iterable<int, PhpReaderControllerInterface>
     */
    public function getReadableWorkers(): iterable;

    public function returnWorkerToPool(PhpReaderControllerInterface $context_to_return): void;

    public function setOnRead(int $pid): void;

    public function releaseOnRead(int $pid): void;
}
