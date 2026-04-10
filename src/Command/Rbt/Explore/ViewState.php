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
 * One frame in the explorer's focus history stack.
 *
 * - $mode               ExploreMode for this state.
 * - $focus_id           Frame id (line- or no-line-space) the sandwich is
 *                       focused on, or null for unfocused list views.
 * - $focus_label        Display label captured at focus time.
 * - $view_filter        PCRE applied to row labels at render time. In
 *                       sandwich mode the same filter is applied to all
 *                       panes; in list mode it filters the list rows.
 * - $active_pane        Sandwich-only. Which pane is currently receiving
 *                       navigation keystrokes (callers / callees / overview).
 * - $overview_selected  Sandwich-only. Restore point for the overview
 *                       cursor, captured at the moment a new sandwich
 *                       state is pushed *on top of* this one. Lets `u`
 *                       put the cursor back where it was before the user
 *                       drilled down.
 * - $overview_top_row   Companion scroll offset for $overview_selected.
 */
final class ViewState
{
    public function __construct(
        public readonly ExploreMode $mode,
        public readonly ?int $focus_id = null,
        public readonly ?string $focus_label = null,
        public readonly ?string $view_filter = null,
        public readonly ActivePane $active_pane = ActivePane::Callers,
        public readonly int $overview_selected = 0,
        public readonly int $overview_top_row = 0,
    ) {
    }

    public function withMode(ExploreMode $mode): self
    {
        return new self(
            $mode,
            $this->focus_id,
            $this->focus_label,
            $this->view_filter,
            $this->active_pane,
            $this->overview_selected,
            $this->overview_top_row,
        );
    }

    public function withViewFilter(?string $filter): self
    {
        return new self(
            $this->mode,
            $this->focus_id,
            $this->focus_label,
            $filter,
            $this->active_pane,
            $this->overview_selected,
            $this->overview_top_row,
        );
    }

    public function withActivePane(ActivePane $active_pane): self
    {
        return new self(
            $this->mode,
            $this->focus_id,
            $this->focus_label,
            $this->view_filter,
            $active_pane,
            $this->overview_selected,
            $this->overview_top_row,
        );
    }

    public function withOverviewCursor(int $selected, int $top_row): self
    {
        return new self(
            $this->mode,
            $this->focus_id,
            $this->focus_label,
            $this->view_filter,
            $this->active_pane,
            $selected,
            $top_row,
        );
    }

    public function withFocus(int $focus_id, string $focus_label): self
    {
        return new self(
            $this->mode,
            $focus_id,
            $focus_label,
            $this->view_filter,
            $this->active_pane,
            $this->overview_selected,
            $this->overview_top_row,
        );
    }
}
