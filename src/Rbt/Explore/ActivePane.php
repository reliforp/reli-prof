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
 * Which region in the sandwich layout currently receives navigation
 * keystrokes. `Tab` cycles `Callers → Focus → Callees → Overview →
 * Callers`; the explicit `←` / `→` keys jump to `Callers` / `Callees`.
 *
 * `Focus` is a synthetic "cursor parking spot" on the focus banner —
 * it has no list to scroll, but lets the user place the cursor on the
 * current focus frame so mode-switching (`P` / `S` / `>` / `<`) treats
 * the switch as in-place rather than as a drilldown into whatever
 * caller / callee was last selected. It's only meaningful in the
 * panes view; the flame and tree views render the focus inline as
 * part of their body, so the Tab cycle skips Focus there.
 *
 * The overview pane only becomes active when the user opts in via
 * `Tab`; otherwise it stays in "auto-scroll, follow the focus" mode.
 */
enum ActivePane: string
{
    case Callers = 'callers';
    case Focus = 'focus';
    case Callees = 'callees';
    case Overview = 'overview';

    public function next(): self
    {
        return match ($this) {
            self::Callers => self::Focus,
            self::Focus => self::Callees,
            self::Callees => self::Overview,
            self::Overview => self::Callers,
        };
    }

    public function prev(): self
    {
        return match ($this) {
            self::Callers => self::Overview,
            self::Focus => self::Callers,
            self::Callees => self::Focus,
            self::Overview => self::Callees,
        };
    }
}
