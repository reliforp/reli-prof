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

interface TargetProcessListInterface
{
    public function pickOne(): ?TargetProcessDescriptor;

    public function putOne(TargetProcessDescriptor $process_descriptor): void;

    public function getDiff(TargetProcessListInterface $compare_list): self;

    /** @return TargetProcessDescriptor[] */
    public function getArray(): array;

    public function removeByPid(int $pid): void;
}
