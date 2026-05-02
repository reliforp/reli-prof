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
 * Resolves the main-executable path **as the target sees it** (i.e.
 * the path string that appears in /proc/<pid>/maps or in the core
 * file's NT_FILE notes — like "/usr/local/bin/php"). The result is a
 * regex / lookup key, not a host-accessible path; turning it into one
 * is `ProcessPathResolver`'s job.
 *
 * Two implementations cover the two common provenances:
 *
 * - {@see ProcExeReadlinkResolver} — live process, reads
 *   /proc/<pid>/exe.
 * - {@see StaticMainExecutablePathResolver} — coredump, fed a
 *   pre-extracted path (typically taken from NT_FILE notes / the
 *   lowest r-x PT_LOAD's matching file).
 */
interface MainExecutablePathResolver
{
    /**
     * @return string|null the target-view path, or null if unknown
     *                     (e.g. live readlink failed because the
     *                     target is dead).
     */
    public function resolve(int $pid): ?string;
}
