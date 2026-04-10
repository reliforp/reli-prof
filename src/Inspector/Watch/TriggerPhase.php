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
 * Represents a trigger state transition detected by TriggerStateTracker.
 */
enum TriggerPhase
{
    /** Condition just became true (was inactive, now active) */
    case ENTER;

    /** Condition just became false (was active, now inactive) */
    case EXIT;

    /** Condition is still true (no transition) */
    case ACTIVE;
}
