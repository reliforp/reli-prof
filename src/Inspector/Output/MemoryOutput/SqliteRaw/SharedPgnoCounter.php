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

namespace Reli\Inspector\Output\MemoryOutput\SqliteRaw;

/**
 * File-locked shared counter for atomically claiming pgno ranges
 * across forked workers.
 *
 * Used by {@see ParallelIndexBuilder} so each worker's CREATE INDEX
 * result lands at exactly the next available pgno in the
 * destination file — eliminating the sparse-hole / freelist tail
 * that the earlier static per-worker reservation produced.
 *
 * Each worker calls {@see claim} after measuring its index's actual
 * page count; the call returns the first pgno of a `$count`-page
 * contiguous range that no other worker can hand out. The lock
 * window is just a read / increment / write of an 8-byte counter,
 * so contention is negligible even with many workers.
 *
 * `flock` ordering note: PHP's flock is advisory and works on the
 * underlying inode, so a worker that `fopen`s the counter file
 * fresh after fork gets its own open-file-description and
 * contends correctly with the others. Workers must NOT use the
 * parent's pre-fork fd, since the offset is shared across the
 * inherited description and a contending fseek would race.
 */
final class SharedPgnoCounter
{
    /**
     * Create the counter file at `$path` and seed it with the
     * starting pgno. Caller-side: typically invoked once by the
     * main process before fork.
     */
    public static function init(string $path, int $initial_pgno): void
    {
        if (file_put_contents($path, pack('J', $initial_pgno)) === false) {
            throw new \RuntimeException("failed to init counter at {$path}");
        }
    }

    /**
     * Atomically claim a contiguous range of `$count` pgnos from
     * the counter file at `$path`. Returns the first pgno of the
     * claimed range. Workers call this from the forked child.
     */
    public static function claim(string $path, int $count): int
    {
        $fp = fopen($path, 'r+b');
        if ($fp === false) {
            throw new \RuntimeException("failed to open counter at {$path}");
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                throw new \RuntimeException("failed to flock counter at {$path}");
            }
            try {
                fseek($fp, 0);
                $bytes = fread($fp, 8);
                if ($bytes === false || strlen($bytes) !== 8) {
                    throw new \RuntimeException("failed to read counter at {$path}");
                }
                /** @var array{1: int} $u */
                $u = unpack('J', $bytes);
                $first = $u[1];
                $new = $first + $count;
                fseek($fp, 0);
                fwrite($fp, pack('J', $new));
                fflush($fp);
                return $first;
            } finally {
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }

    /** Read the counter's current value (no claim). */
    public static function peek(string $path): int
    {
        $bytes = (string)file_get_contents($path);
        if (strlen($bytes) !== 8) {
            throw new \RuntimeException("malformed counter at {$path}");
        }
        /** @var array{1: int} $u */
        $u = unpack('J', $bytes);
        return $u[1];
    }
}
