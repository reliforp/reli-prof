<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */


namespace PhpProfiler\ProcessReader;

/**
 * Class ProcessMemoryMapReader
 * @package PhpProfiler\ProcessReader
 */
final class ProcessMemoryMapReader
{
    /**
     * @param int $process_id
     * @return string
     */
    public function read(int $process_id): string
    {
        return file_get_contents("/proc/{$process_id}/maps");
    }
}