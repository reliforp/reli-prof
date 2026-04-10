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

namespace Reli\Command\Rbt\Explore;

/**
 * Top-level layout mode for {@see ExploreTui}.
 *
 * - {@see self::ListSelf} / {@see self::ListTotal}: a single hot-frame
 *   table — the entry view, also reachable any time via the `s` / `t`
 *   keys. There is no focus yet, so callers/callees views aren't
 *   meaningful.
 *
 * - {@see self::Sandwich}: callers pane on top, focus banner in the
 *   middle, callees pane on the bottom. Tab toggles which side pane
 *   is active for ↑↓ navigation; ←/→ explicitly pick a side. Reached
 *   by pressing Enter on a row in a list view.
 */
enum ExploreMode: string
{
    case ListSelf = 'list_self';
    case ListTotal = 'list_total';
    case Sandwich = 'sandwich';
}
