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
 * Which pane in the sandwich layout currently receives navigation
 * keystrokes. `Tab` cycles `Callers → Callees → Overview → Callers`;
 * the explicit `←` / `→` keys jump to `Callers` / `Callees`.
 *
 * The overview pane only becomes active when the user opts in via
 * `Tab`; otherwise it stays in "auto-scroll, follow the focus" mode.
 */
enum ActivePane: string
{
    case Callers = 'callers';
    case Callees = 'callees';
    case Overview = 'overview';

    public function next(): self
    {
        return match ($this) {
            self::Callers => self::Callees,
            self::Callees => self::Overview,
            self::Overview => self::Callers,
        };
    }

    public function prev(): self
    {
        return match ($this) {
            self::Callers => self::Overview,
            self::Callees => self::Callers,
            self::Overview => self::Callees,
        };
    }
}
