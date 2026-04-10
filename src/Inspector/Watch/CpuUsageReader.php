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
 *
 * To avoid noisy readings when the poll interval is very short (e.g. 10ms
 * during continuous tracing), a minimum sampling window is enforced. The
 * baseline sample is only replaced when at least $min_window_seconds have
 * elapsed, so shorter polls reuse the same baseline and produce a stable
 * moving-average CPU%.
 */
final class CpuUsageReader
{
    private int $clk_tck;

    /** @var array<int, array{ticks: int, time: float}> baseline sample per PID */
    private array $baseline = [];

    /** @var array<int, float> last computed CPU% per PID (returned when window not yet elapsed) */
    private array $last_percent = [];

    /**
     * @param float $min_window_seconds Minimum time window for CPU% calculation.
     *        Shorter polls reuse the previous baseline. Default 0.5s gives
     *        a good balance between responsiveness and noise reduction.
     */
    public function __construct(
        private float $min_window_seconds = 0.5,
    ) {
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
     * First call for a PID always returns null (no baseline yet).
     * Subsequent calls within min_window_seconds of the baseline
     * return the last computed value without updating the baseline.
     *
     * @return float|null CPU usage in percent (e.g. 85.3), or null if unreadable / first sample
     */
    public function read(int $pid): ?float
    {
        $sample = $this->readSample($pid);
        if ($sample === null) {
            return null;
        }
        [$total_ticks, $now] = $sample;

        $base = $this->baseline[$pid] ?? null;
        if ($base === null) {
            // First sample — store as baseline, no CPU% yet
            $this->baseline[$pid] = ['ticks' => $total_ticks, 'time' => $now];
            return null;
        }

        $dt = $now - $base['time'];
        if ($dt < $this->min_window_seconds) {
            // Window not yet elapsed — return last known value
            return $this->last_percent[$pid] ?? null;
        }

        // Window elapsed — compute new CPU% and rotate baseline
        $dticks = $total_ticks - $base['ticks'];
        if ($dticks < 0) {
            // Counter wrapped or process restarted — reset
            $this->baseline[$pid] = ['ticks' => $total_ticks, 'time' => $now];
            unset($this->last_percent[$pid]);
            return null;
        }

        $percent = ($dticks / $this->clk_tck) / $dt * 100.0;
        $this->baseline[$pid] = ['ticks' => $total_ticks, 'time' => $now];
        $this->last_percent[$pid] = $percent;
        return $percent;
    }

    /**
     * Read raw ticks from /proc/[pid]/stat.
     *
     * @return array{int, float}|null [total_ticks, timestamp] or null
     */
    private function readSample(int $pid): ?array
    {
        $content = @\file_get_contents("/proc/{$pid}/stat");
        if ($content === false) {
            return null;
        }

        // /proc/[pid]/stat format: pid (comm) state field4 ... field14(utime) field15(stime) ...
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
        return [$utime + $stime, \microtime(true)];
    }

    /**
     * Clear stored state for a PID (e.g. when process exits).
     */
    public function clear(int $pid): void
    {
        unset($this->baseline[$pid], $this->last_percent[$pid]);
    }
}
