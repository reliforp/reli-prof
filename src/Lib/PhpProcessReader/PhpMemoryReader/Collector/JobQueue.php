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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector;

final class JobQueue
{
    /** @var list<CollectorJob> */
    private array $stack = [];

    public function push(CollectorJob $job): void
    {
        $this->stack[] = $job;
    }

    public function pop(): ?CollectorJob
    {
        return array_pop($this->stack);
    }

    public function isEmpty(): bool
    {
        return $this->stack === [];
    }
}
