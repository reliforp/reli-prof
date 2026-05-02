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

namespace Reli\Lib\PhpProcessReader\MainExecutable;

/**
 * Default {@see MainExecutablePathResolver}: looks up the target's
 * own view of its executable via /proc/<pid>/exe. Returns null if
 * the symlink cannot be read — the typical signal that the target
 * has exited (in which case downstream callers should fall back to
 * the post-mortem `MainExecutablePathResolver` plumbed by
 * `CoreDumpReaderFactory`).
 */
final class ProcExeReadlinkResolver implements MainExecutablePathResolver
{
    #[\Override]
    public function resolve(int $pid): ?string
    {
        $r = @\readlink("/proc/{$pid}/exe");
        return $r === false ? null : $r;
    }
}
