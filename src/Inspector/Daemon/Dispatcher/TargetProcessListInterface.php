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
    public function pickOne(): ?int;
    public function putOne(int $pid): void;
    public function getDiff(TargetProcessList $compare_list): self;
    public function getArray(): array;
}
