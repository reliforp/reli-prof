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

namespace Reli\Rbt\Explore;

/**
 * Display variant of {@see ExploreMode::Sandwich}.
 *
 * The same focus / overview / mini-flame layout frame is shared across
 * every variant; only the body content shown to the right of the
 * overview sidebar changes:
 *
 * - {@see self::Panes}        Original two-pane drill-down: callers
 *                             list above the focus banner, callees
 *                             list below. Best for picking out a
 *                             single hot caller/callee one step at a
 *                             time.
 *
 * - {@see self::Flame}        Speedscope-style flame chart with the
 *                             focus bar in the middle, callers stacked
 *                             above and callees stacked below. Best
 *                             for "where is the mass" overall view.
 *
 * - {@see self::TreeCallees}  Indented full-label tree growing from
 *                             the focus toward leaves, with a Braille
 *                             percent bar per row. Best when the flame
 *                             chart's narrow bars hide the labels you
 *                             want to read.
 *
 * - {@see self::TreeCallers}  Same as TreeCallees but growing toward
 *                             roots.
 *
 * Mode switching ({@see Keymap::ACTION_VIEW_PANES} etc.) is built so
 * that the cursor's current frame becomes the new focus on the way
 * across, so the user can navigate inside one view and then switch
 * to another to inspect the same frame from a different angle.
 */
enum SandwichView: string
{
    case Panes       = 'panes';
    case Flame       = 'flame';
    case TreeCallees = 'tree_callees';
    case TreeCallers = 'tree_callers';
}
