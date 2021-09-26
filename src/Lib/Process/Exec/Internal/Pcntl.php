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

namespace PhpProfiler\Lib\Process\Exec\Internal;

class Pcntl
{
    public function fork(): int
    {
        return \pcntl_fork();
    }

    /** @param-out int $status */
    public function waitpid(int $process_id, ?int &$status, int $flags = 0): int
    {
        return \pcntl_waitpid(
            $process_id,
            $status,
            $flags
        );
    }

    public function wifstopped(int $status): bool
    {
        return \pcntl_wifstopped($status);
    }

    public function wstopsig(int $status): int|false
    {
        return \pcntl_wstopsig($status);
    }
}
