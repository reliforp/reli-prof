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

namespace Reli\Inspector\Daemon\PhpSpy;

use Reli\Inspector\Settings\PhpSpySettings\PhpSpySettings;
use Reli\Lib\PhpSpy\PhpSpyFinder;
use Reli\Lib\PhpSpy\PhpSpyProcess;

final class PhpSpyProcessPool
{
    /** @var array<int, PhpSpyProcess> pid => process */
    private array $processes = [];

    public function __construct(
        private PhpSpyFinder $phpspy_finder,
    ) {
    }

    public function attach(
        int $pid,
        int $eg_address,
        int $sg_address,
        int $depth,
        PhpSpySettings $settings,
    ): void {
        if (isset($this->processes[$pid])) {
            return;
        }
        $process = new PhpSpyProcess($this->phpspy_finder);
        $process->start($pid, $eg_address, $sg_address, $depth, $settings);
        $this->processes[$pid] = $process;
    }

    public function detach(int $pid): void
    {
        if (isset($this->processes[$pid])) {
            $this->processes[$pid]->stop();
            unset($this->processes[$pid]);
        }
    }

    /**
     * Passthrough output from all running phpspy processes.
     *
     * @param resource $output_stream
     */
    public function passthroughAll($output_stream): void
    {
        foreach ($this->processes as $pid => $process) {
            if (!$process->isRunning()) {
                $process->passthrough($output_stream);
                $process->stop();
                unset($this->processes[$pid]);
                continue;
            }
            $process->passthrough($output_stream);
        }
    }

    /** @return list<int> */
    public function getActivePids(): array
    {
        return array_keys($this->processes);
    }

    public function hasProcess(int $pid): bool
    {
        return isset($this->processes[$pid]);
    }

    public function stopAll(): void
    {
        foreach ($this->processes as $process) {
            $process->stop();
        }
        $this->processes = [];
    }

    public function count(): int
    {
        return count($this->processes);
    }
}
