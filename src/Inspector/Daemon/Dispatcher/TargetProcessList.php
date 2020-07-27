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

final class TargetProcessList implements TargetProcessListInterface
{
    /** @var int[] */
    private array $pid_list;

    public function __construct(int ...$pid_list)
    {
        $this->pid_list = $pid_list;
    }

    public function pickOne(): ?int
    {
        if ($this->pid_list === []) {
            return null;
        }
        $key = array_rand($this->pid_list);
        $value = $this->pid_list[$key];
        unset($this->pid_list[$key]);
        return $value;
    }

    public function putOne(int $pid): void
    {
        $this->pid_list[] = $pid;
    }

    public function getDiff(TargetProcessList $compare_list): self
    {
        return new self(...array_diff($this->pid_list, $compare_list->pid_list));
    }

    /**
     * @return int[]
     */
    public function getArray(): array
    {
        return $this->pid_list;
    }
}
