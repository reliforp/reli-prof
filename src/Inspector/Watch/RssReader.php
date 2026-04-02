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

namespace Reli\Inspector\Watch;

/**
 * Reads RSS (Resident Set Size) from /proc/[pid]/statm.
 *
 * /proc/[pid]/statm fields (all in pages):
 *   0: total program size
 *   1: resident set size (RSS)
 *   2: shared pages
 *   3: text (code)
 *   4: lib (unused since Linux 2.6)
 *   5: data + stack
 *   6: dirty pages (unused since Linux 2.6)
 */
final class RssReader
{
    private int $page_size;

    public function __construct()
    {
        $this->page_size = self::detectPageSize();
    }

    private static function detectPageSize(): int
    {
        if (\function_exists('posix_sysconf') && \defined('POSIX_SC_PAGESIZE')) {
            /** @var int $page_size */
            $page_size = \posix_sysconf(\POSIX_SC_PAGESIZE);
            if ($page_size > 0) {
                return $page_size;
            }
        }
        return 4096;
    }

    /**
     * Read RSS in bytes for the given PID.
     *
     * @return int|null RSS in bytes, or null if unreadable
     */
    public function read(int $pid): ?int
    {
        $content = @\file_get_contents("/proc/{$pid}/statm");
        if ($content === false) {
            return null;
        }
        $fields = \explode(' ', \trim($content));
        if (!isset($fields[1])) {
            return null;
        }
        return (int)$fields[1] * $this->page_size;
    }
}
