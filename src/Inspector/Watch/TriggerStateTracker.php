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
 * Tracks per-trigger active/inactive state and detects transitions.
 *
 * Wraps the existing trigger evaluation to provide on-enter / on-exit
 * lifecycle semantics without changing TriggerInterface.
 */
final class TriggerStateTracker
{
    /** @var array<string, bool> trigger name → currently active */
    private array $states = [];

    /**
     * Update state for a trigger and return the phase.
     *
     * @return TriggerPhase|null ENTER, EXIT, ACTIVE, or null (still inactive)
     */
    public function update(string $trigger_name, bool $is_active): ?TriggerPhase
    {
        $was_active = $this->states[$trigger_name] ?? false;
        $this->states[$trigger_name] = $is_active;

        if (!$was_active && $is_active) {
            return TriggerPhase::ENTER;
        }
        if ($was_active && !$is_active) {
            return TriggerPhase::EXIT;
        }
        if ($is_active) {
            return TriggerPhase::ACTIVE;
        }
        return null; // was inactive, still inactive
    }

    public function isActive(string $trigger_name): bool
    {
        return $this->states[$trigger_name] ?? false;
    }

    public function hasAnyActive(): bool
    {
        return \in_array(true, $this->states, true);
    }
}
