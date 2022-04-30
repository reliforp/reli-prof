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

use function array_rand;
use function array_udiff;

final class TargetProcessList implements TargetProcessListInterface
{
    /** @var TargetProcessDescriptor[] */
    private array $process_list;

    public function __construct(TargetProcessDescriptor ...$process_list)
    {
        $this->process_list = $process_list;
    }

    public function pickOne(): ?TargetProcessDescriptor
    {
        if ($this->process_list === []) {
            return null;
        }
        $key = array_rand($this->process_list);
        $value = $this->process_list[$key];
        unset($this->process_list[$key]);
        return $value;
    }

    public function putOne(TargetProcessDescriptor $process_descriptor): void
    {
        $this->process_list[] = $process_descriptor;
    }

    public function getDiff(TargetProcessListInterface $compare_list): self
    {
        /** @var TargetProcessDescriptor[] $diff */
        $diff = array_udiff(
            $this->process_list,
            $compare_list->getArray(),
            fn (TargetProcessDescriptor $a, TargetProcessDescriptor $b) => $a <=> $b,
        );
        return new self(
            ...$diff
        );
    }

    /** @return TargetProcessDescriptor[] */
    public function getArray(): array
    {
        return $this->process_list;
    }

    public function removeByPid(int $pid): void
    {
        foreach ($this->process_list as $key => $process_descriptor) {
            if ($process_descriptor->pid === $pid) {
                unset($this->process_list[$key]);
            }
        }
    }
}
