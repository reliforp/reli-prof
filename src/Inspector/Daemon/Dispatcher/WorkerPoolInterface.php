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

interface WorkerPoolInterface
{
    public function getFreeWorker(): ?PhpReaderControllerInterface;

    public function returnWorkerToPool(PhpReaderControllerInterface $context_to_return): void;

    /** @return iterable<int, PhpReaderControllerInterface> */
    public function getWorkers(): iterable;

    public function debugDump(): array;
}
