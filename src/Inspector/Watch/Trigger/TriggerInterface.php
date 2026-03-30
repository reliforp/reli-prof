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

use Reli\Inspector\Watch\TriggerEvent;
use Reli\Inspector\Watch\WatchContext;

interface TriggerInterface
{
    public function name(): string;

    /** Whether this trigger requires call trace reading (Tier 2) */
    public function requiresCallTrace(): bool;

    /** Whether this trigger requires deep EG inspection (Tier 3) */
    public function requiresDeepInspection(): bool;

    /** Evaluate the trigger condition. Returns TriggerEvent if fired, null otherwise. */
    public function evaluate(WatchContext $context): ?TriggerEvent;
}
