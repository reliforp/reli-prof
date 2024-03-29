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

interface TargetProcessListInterface
{
    public function pickOne(): ?TargetProcessDescriptor;

    public function putOne(TargetProcessDescriptor $process_descriptor): void;

    public function getDiff(TargetProcessListInterface $compare_list): self;

    /** @return TargetProcessDescriptor[] */
    public function getArray(): array;

    public function removeByPid(int $pid): void;
}
