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

final class CooldownManager
{
    /** @var array<string, CooldownState> */
    private array $states = [];
    private int $total_fires = 0;

    public function __construct(
        private float $base_cooldown_seconds,
        private float $backoff_multiplier,
        private float $backoff_max_seconds,
        private int $max_triggers_per_hour,
        private int $max_triggers,
    ) {
    }

    public function canFire(string $trigger_name, float $now): bool
    {
        if ($this->max_triggers > 0 && $this->total_fires >= $this->max_triggers) {
            return false;
        }

        if ($this->getHourlyCount($now) >= $this->max_triggers_per_hour) {
            return false;
        }

        $state = $this->states[$trigger_name] ?? null;
        if ($state === null) {
            return true;
        }

        return ($now - $state->last_fire_time) >= $state->current_cooldown;
    }

    public function recordFire(string $trigger_name, float $now): void
    {
        $this->total_fires++;

        if (!isset($this->states[$trigger_name])) {
            $this->states[$trigger_name] = new CooldownState(
                last_fire_time: $now,
                consecutive_fires: 1,
                current_cooldown: $this->base_cooldown_seconds,
                hourly_timestamps: new \SplQueue(),
            );
            $this->states[$trigger_name]->hourly_timestamps->enqueue($now);
            return;
        }

        $state = $this->states[$trigger_name];
        $state->last_fire_time = $now;
        $state->consecutive_fires++;
        $state->current_cooldown = min(
            $this->base_cooldown_seconds * ($this->backoff_multiplier ** ($state->consecutive_fires - 1)),
            $this->backoff_max_seconds,
        );
        $state->hourly_timestamps->enqueue($now);
    }

    public function recordClear(string $trigger_name): void
    {
        if (isset($this->states[$trigger_name])) {
            $this->states[$trigger_name]->consecutive_fires = 0;
            $this->states[$trigger_name]->current_cooldown = $this->base_cooldown_seconds;
        }
    }

    public function getHourlyCount(float $now): int
    {
        $count = 0;
        $one_hour_ago = $now - 3600.0;
        foreach ($this->states as $state) {
            while (!$state->hourly_timestamps->isEmpty()) {
                /** @var float $ts */
                $ts = $state->hourly_timestamps->bottom();
                if ($ts < $one_hour_ago) {
                    $state->hourly_timestamps->dequeue();
                } else {
                    break;
                }
            }
            $count += $state->hourly_timestamps->count();
        }
        return $count;
    }

    public function getTotalFires(): int
    {
        return $this->total_fires;
    }

    public function getSkipReason(string $trigger_name, float $now): ?string
    {
        if ($this->max_triggers > 0 && $this->total_fires >= $this->max_triggers) {
            return sprintf('max triggers reached (%d/%d)', $this->total_fires, $this->max_triggers);
        }

        $hourly = $this->getHourlyCount($now);
        if ($hourly >= $this->max_triggers_per_hour) {
            return sprintf('hourly limit reached (%d/%d)', $hourly, $this->max_triggers_per_hour);
        }

        $state = $this->states[$trigger_name] ?? null;
        if ($state !== null) {
            $remaining = $state->current_cooldown - ($now - $state->last_fire_time);
            if ($remaining > 0) {
                return sprintf('cooldown (%.0fs remaining)', $remaining);
            }
        }

        return null;
    }

    public function hasReachedMaxTriggers(): bool
    {
        return $this->max_triggers > 0 && $this->total_fires >= $this->max_triggers;
    }
}

/** @internal */
final class CooldownState
{
    public function __construct(
        public float $last_fire_time,
        public int $consecutive_fires,
        public float $current_cooldown,
        /** @var \SplQueue<float> */
        public \SplQueue $hourly_timestamps,
    ) {
    }
}
