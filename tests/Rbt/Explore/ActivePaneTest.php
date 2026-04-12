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

use Reli\BaseTestCase;

/**
 * ActivePane is a 4-state enum with next()/prev() walking the
 * Tab cycle Callers → Focus → Callees → Overview → Callers.
 * The tests just walk both directions and assert the cycle closes.
 */
class ActivePaneTest extends BaseTestCase
{
    public function testNextWalksTheFullCycle(): void
    {
        $this->assertSame(ActivePane::Focus, ActivePane::Callers->next());
        $this->assertSame(ActivePane::Callees, ActivePane::Focus->next());
        $this->assertSame(ActivePane::Overview, ActivePane::Callees->next());
        // Closing the loop.
        $this->assertSame(ActivePane::Callers, ActivePane::Overview->next());
    }

    public function testPrevWalksTheFullCycleInReverse(): void
    {
        $this->assertSame(ActivePane::Overview, ActivePane::Callers->prev());
        $this->assertSame(ActivePane::Callees, ActivePane::Overview->prev());
        $this->assertSame(ActivePane::Focus, ActivePane::Callees->prev());
        // Closing the loop.
        $this->assertSame(ActivePane::Callers, ActivePane::Focus->prev());
    }

    public function testNextAndPrevAreInversesAtEverySlot(): void
    {
        foreach (ActivePane::cases() as $pane) {
            $this->assertSame(
                $pane,
                $pane->next()->prev(),
                "next->prev should round-trip {$pane->value}",
            );
            $this->assertSame(
                $pane,
                $pane->prev()->next(),
                "prev->next should round-trip {$pane->value}",
            );
        }
    }

    public function testFourFullNextStepsReturnToStart(): void
    {
        // The cycle has exactly 4 panes, so 4 .next() calls is identity.
        $start = ActivePane::Callers;
        $end = $start->next()->next()->next()->next();
        $this->assertSame($start, $end);
    }
}
