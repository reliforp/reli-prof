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

namespace Reli\Inspector\Daemon\Searcher\Worker;

use Reli\Inspector\Daemon\Dispatcher\TargetProcessDescriptor;

final class ProcessDescriptorCache
{
    /** @var array<int, TargetProcessDescriptor> */
    private $cache = [];

    /**
     * Descriptors that have fully resolved EG/SG addresses but whose
     * EG(active) was 0 at the time of analysis (e.g. mod_php parent
     * httpd, or a worker still booting). Next cycle only needs to
     * re-read EG(active) to promote them into $cache.
     *
     * @var array<int, TargetProcessDescriptor>
     */
    private array $pending_cache = [];

    public function set(TargetProcessDescriptor $target_process_descriptor): void
    {
        $this->cache[$target_process_descriptor->pid] = $target_process_descriptor;
        unset($this->pending_cache[$target_process_descriptor->pid]);
    }

    public function setInvalid(int $pid): void
    {
        $this->cache[$pid] = TargetProcessDescriptor::getInvalid();
        unset($this->pending_cache[$pid]);
    }

    public function setPending(TargetProcessDescriptor $target_process_descriptor): void
    {
        $this->pending_cache[$target_process_descriptor->pid] = $target_process_descriptor;
    }

    public function get(int $pid): ?TargetProcessDescriptor
    {
        return $this->cache[$pid] ?? null;
    }

    public function getPending(int $pid): ?TargetProcessDescriptor
    {
        return $this->pending_cache[$pid] ?? null;
    }

    public function removeDisappeared(int ...$pids): void
    {
        $cached = array_keys($this->cache);
        $diff = array_diff($cached, $pids);
        foreach ($diff as $disappeared) {
            unset($this->cache[$disappeared]);
        }
        $pending = array_keys($this->pending_cache);
        $pending_diff = array_diff($pending, $pids);
        foreach ($pending_diff as $disappeared) {
            unset($this->pending_cache[$disappeared]);
        }
    }
}
