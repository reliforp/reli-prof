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
 * {@see MainExecutablePathResolver} for the coredump path: returns a
 * pre-extracted path string regardless of $pid. `CoreDumpReaderFactory`
 * pulls the path from the core file's NT_FILE notes (the lowest
 * executable PT_LOAD's matching file map) and feeds it here, so
 * post-mortem analysis no longer needs /proc/<pid>/exe to exist.
 */
final class StaticMainExecutablePathResolver implements MainExecutablePathResolver
{
    public function __construct(
        private ?string $path,
    ) {
    }

    #[\Override]
    public function resolve(int $pid): ?string
    {
        return $this->path;
    }
}
