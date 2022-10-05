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

namespace PhpProfiler\Inspector\Daemon\Searcher\Worker;

use PhpProfiler\Inspector\Daemon\Dispatcher\TargetProcessDescriptor;

final class ProcessDescriptorCache
{
    /** @var array<int, TargetProcessDescriptor> */
    private $cache = [];

    public function set(TargetProcessDescriptor $target_process_descriptor): void
    {
        $this->cache[$target_process_descriptor->pid] = $target_process_descriptor;
    }

    public function setInvalid(int $pid): void
    {
        $this->cache[$pid] = TargetProcessDescriptor::getInvalid();
    }

    public function get(int $pid): ?TargetProcessDescriptor
    {
        return $this->cache[$pid] ?? null;
    }

    public function removeDisappeared(int ...$pids): void
    {
        $cached = array_keys($this->cache);
        $diff = array_diff($cached, $pids);
        foreach ($diff as $disappeared) {
            unset($this->cache[$disappeared]);
        }
    }
}
