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

namespace Reli\Inspector\Watch\Trigger;

use Reli\Inspector\Watch\HeapStats;
use Reli\Inspector\Watch\TriggerEvent;
use Reli\Inspector\Watch\WatchContext;

final class MemoryGrowthRateTrigger implements TriggerInterface
{
    private int $threshold_bytes_per_second;

    /**
     * @param int $bytes_per_period  Threshold in bytes per period
     * @param int $period_seconds    Period length in seconds
     */
    public function __construct(
        int $bytes_per_period,
        int $period_seconds,
    ) {
        $this->threshold_bytes_per_second = (int)($bytes_per_period / max($period_seconds, 1));
    }

    #[\Override]
    public function name(): string
    {
        return 'memory-growth-rate';
    }

    #[\Override]
    public function requiresCallTrace(): bool
    {
        return false;
    }

    #[\Override]
    public function requiresDeepInspection(): bool
    {
        return false;
    }

    #[\Override]
    public function evaluate(WatchContext $context): ?TriggerEvent
    {
        if ($context->previous === null) {
            return null;
        }

        $elapsed = $context->timestamp - $context->previous->timestamp;
        if ($elapsed <= 0.0) {
            return null;
        }

        $growth = $context->heap_stats->size - $context->previous->heap_stats->size;
        if ($growth <= 0) {
            return null;
        }

        $rate_per_second = (int)((float)$growth / $elapsed);
        if ($rate_per_second > $this->threshold_bytes_per_second) {
            return new TriggerEvent(
                trigger_name: $this->name(),
                description: sprintf(
                    'growth=%s/s (threshold=%s/s)',
                    HeapStats::humanReadableBytes($rate_per_second),
                    HeapStats::humanReadableBytes($this->threshold_bytes_per_second),
                ),
                timestamp: $context->timestamp,
                value: (float)$rate_per_second,
            );
        }
        return null;
    }

    /**
     * Parse a growth rate string like "10M/min" into bytes_per_period and period_seconds.
     *
     * @return array{int, int} [bytes_per_period, period_seconds]
     */
    public static function parseRate(string $rate): array
    {
        if (!preg_match('/^(\d+(?:\.\d+)?)\s*([KMGT]?)\s*\/\s*(s|sec|min|h|hour)$/i', $rate, $matches)) {
            throw new \InvalidArgumentException(
                "Invalid growth rate format: {$rate}."
                . " Expected format: <size>/<period> (e.g., 10M/min)"
            );
        }

        $value = (float)$matches[1];
        $unit = strtoupper($matches[2]);
        $period = strtolower($matches[3]);

        $bytes = match ($unit) {
            'K' => (int)((int)$value * 1024),
            'M' => (int)((int)$value * 1024 * 1024),
            'G' => (int)((int)$value * 1024 * 1024 * 1024),
            'T' => (int)((int)$value * 1024 * 1024 * 1024 * 1024),
            default => (int)$value,
        };

        $seconds = match ($period) {
            's', 'sec' => 1,
            'min' => 60,
            'h', 'hour' => 3600,
            default => throw new \InvalidArgumentException("Unknown period: {$period}"),
        };

        return [$bytes, $seconds];
    }
}
