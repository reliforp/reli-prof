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

namespace Reli\Lib\Process\ProcFileSystem;

final class ThreadNameReader
{
    public function read(int $tid): ?string
    {
        $raw = @file_get_contents("/proc/{$tid}/comm");
        if ($raw === false) {
            return null;
        }
        return rtrim($raw, "\n");
    }
}
