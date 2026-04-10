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
 * Reads per-process CPU usage from /proc/[pid]/stat.
 *
 * /proc/[pid]/stat fields (1-indexed):
 *   14: utime — user mode jiffies
 *   15: stime — kernel mode jiffies
 *
 * CPU% = delta(utime + stime) / delta(wall_time) * 100 / SC_CLK_TCK
 *
 * Returns percentage (0.0–N*100.0 for N cores) for the single process.
 */
final class CpuUsageReader
{
    private int $clk_tck;

    /** @var array<int, array{ticks: int, time: float}> previous sample per PID */
    private array $previous = [];

    public function __construct()
    {
        $this->clk_tck = self::detectClkTck();
    }

    private static function detectClkTck(): int
    {
        if (\function_exists('posix_sysconf') && \defined('POSIX_SC_CLK_TCK')) {
            /** @var int $val */
            $val = \posix_sysconf(\POSIX_SC_CLK_TCK);
            if ($val > 0) {
                return $val;
            }
        }
        return 100; // Linux default
    }

    /**
     * Read CPU usage percentage for the given PID.
     *
     * First call for a PID always returns null (no delta yet).
     *
     * @return float|null CPU usage in percent (e.g. 85.3), or null if unreadable / first sample
     */
    public function read(int $pid): ?float
    {
        $content = @\file_get_contents("/proc/{$pid}/stat");
        if ($content === false) {
            return null;
        }

        // /proc/[pid]/stat format: pid (comm) state field4 field5 ... field14(utime) field15(stime) ...
        // comm may contain spaces and parentheses, so find the last ')' first
        $close_paren = \strrpos($content, ')');
        if ($close_paren === false) {
            return null;
        }
        $after_comm = \substr($content, $close_paren + 2); // skip ") "
        $fields = \explode(' ', $after_comm);

        // After comm: field3=state, field4, ..., field14=utime(index 11), field15=stime(index 12)
        if (!isset($fields[12])) {
            return null;
        }

        $utime = (int)$fields[11];
        $stime = (int)$fields[12];
        $total_ticks = $utime + $stime;
        $now = \microtime(true);

        $prev = $this->previous[$pid] ?? null;
        $this->previous[$pid] = ['ticks' => $total_ticks, 'time' => $now];

        if ($prev === null) {
            return null;
        }

        $dt = $now - $prev['time'];
        if ($dt <= 0.0) {
            return null;
        }

        $dticks = $total_ticks - $prev['ticks'];
        if ($dticks < 0) {
            // Counter wrapped or process restarted
            return null;
        }

        // ticks / clk_tck = CPU seconds consumed
        // CPU% = (CPU seconds / wall seconds) * 100
        return ($dticks / $this->clk_tck) / $dt * 100.0;
    }

    /**
     * Clear stored state for a PID (e.g. when process exits).
     */
    public function clear(int $pid): void
    {
        unset($this->previous[$pid]);
    }
}
