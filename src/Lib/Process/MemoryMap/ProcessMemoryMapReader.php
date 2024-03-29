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

namespace Reli\Lib\Process\MemoryMap;

final class ProcessMemoryMapReader
{
    public function read(int $process_id): string
    {
        return file_get_contents("/proc/{$process_id}/maps");
    }
}
