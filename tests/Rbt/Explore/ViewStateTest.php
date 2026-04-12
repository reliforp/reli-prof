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
 * ViewState is the per-frame entry on the explorer's history stack.
 * It's a pure value object built around a fan of withX() copy-on-write
 * helpers, so the tests just exercise (a) the defaults a bare instance
 * gets and (b) that each withX() returns a new instance with exactly
 * the named field changed and everything else preserved.
 */
class ViewStateTest extends BaseTestCase
{
    public function testDefaultsApplyForBareConstructor(): void
    {
        $state = new ViewState(mode: ExploreMode::ListSelf);

        $this->assertSame(ExploreMode::ListSelf, $state->mode);
        $this->assertSame(SandwichView::Panes, $state->sandwich_view);
        $this->assertNull($state->focus_id);
        $this->assertNull($state->focus_label);
        $this->assertNull($state->view_filter);
        $this->assertSame(ActivePane::Callers, $state->active_pane);
        $this->assertSame(0, $state->overview_selected);
        $this->assertSame(0, $state->overview_top_row);
    }

    public function testWithModeReplacesOnlyMode(): void
    {
        $state = $this->seededState();
        $next = $state->withMode(ExploreMode::ListTotal);

        $this->assertNotSame($state, $next);
        $this->assertSame(ExploreMode::ListTotal, $next->mode);
        $this->assertSame($state->sandwich_view, $next->sandwich_view);
        $this->assertSame($state->focus_id, $next->focus_id);
        $this->assertSame($state->focus_label, $next->focus_label);
        $this->assertSame($state->view_filter, $next->view_filter);
        $this->assertSame($state->active_pane, $next->active_pane);
        $this->assertSame($state->overview_selected, $next->overview_selected);
        $this->assertSame($state->overview_top_row, $next->overview_top_row);
    }

    public function testWithSandwichViewReplacesOnlySandwichView(): void
    {
        $state = $this->seededState();
        $next = $state->withSandwichView(SandwichView::Flame);

        $this->assertSame(SandwichView::Flame, $next->sandwich_view);
        $this->assertSame($state->mode, $next->mode);
        $this->assertSame($state->focus_id, $next->focus_id);
        $this->assertSame($state->active_pane, $next->active_pane);
    }

    public function testWithViewFilterReplacesOnlyViewFilter(): void
    {
        $state = $this->seededState();
        $next = $state->withViewFilter('foo|bar');

        $this->assertSame('foo|bar', $next->view_filter);
        $this->assertSame($state->mode, $next->mode);
        $this->assertSame($state->sandwich_view, $next->sandwich_view);
        $this->assertSame($state->focus_id, $next->focus_id);

        $cleared = $next->withViewFilter(null);
        $this->assertNull($cleared->view_filter);
    }

    public function testWithActivePaneReplacesOnlyActivePane(): void
    {
        $state = $this->seededState();
        $next = $state->withActivePane(ActivePane::Callees);

        $this->assertSame(ActivePane::Callees, $next->active_pane);
        $this->assertSame($state->mode, $next->mode);
        $this->assertSame($state->focus_id, $next->focus_id);
    }

    public function testWithOverviewCursorReplacesOnlyOverviewCursorAndTopRow(): void
    {
        $state = $this->seededState();
        $next = $state->withOverviewCursor(7, 4);

        $this->assertSame(7, $next->overview_selected);
        $this->assertSame(4, $next->overview_top_row);
        $this->assertSame($state->mode, $next->mode);
        $this->assertSame($state->focus_id, $next->focus_id);
    }

    public function testWithFocusReplacesIdAndLabelButLeavesScrollState(): void
    {
        $state = $this->seededState();
        $next = $state->withFocus(99, 'someFn /file.php:1');

        $this->assertSame(99, $next->focus_id);
        $this->assertSame('someFn /file.php:1', $next->focus_label);
        // Overview cursor was already set on the seeded state — withFocus
        // shouldn't disturb it; the explorer relies on that to keep the
        // sidebar's "you are here" position when follow mode flips the focus.
        $this->assertSame($state->overview_selected, $next->overview_selected);
        $this->assertSame($state->overview_top_row, $next->overview_top_row);
    }

    /**
     * Build a non-default ViewState so each withX() test can prove it
     * leaves every other field alone, not just the trivial null defaults.
     */
    private function seededState(): ViewState
    {
        return new ViewState(
            mode: ExploreMode::Sandwich,
            sandwich_view: SandwichView::TreeCallees,
            focus_id: 12,
            focus_label: 'foo /a.php:1',
            view_filter: 'App\\\\',
            active_pane: ActivePane::Callees,
            overview_selected: 5,
            overview_top_row: 2,
        );
    }
}
