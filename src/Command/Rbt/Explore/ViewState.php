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
 * - $mode             ExploreMode for this state.
 * - $focus_id         Frame id (line- or no-line-space) the sandwich is
 *                     focused on, or null for unfocused list views.
 * - $focus_label      Display label captured at focus time.
 * - $view_filter      PCRE applied to row labels at render time. In
 *                     sandwich mode the same filter is applied to both
 *                     panes; in list mode it filters the list rows.
 * - $callers_active   Sandwich-only. Which side pane is currently
 *                     receiving navigation keystrokes.
 */
final class ViewState
{
    public function __construct(
        public readonly ExploreMode $mode,
        public readonly ?int $focus_id = null,
        public readonly ?string $focus_label = null,
        public readonly ?string $view_filter = null,
        public readonly bool $callers_active = true,
    ) {
    }

    public function withMode(ExploreMode $mode): self
    {
        return new self(
            $mode,
            $this->focus_id,
            $this->focus_label,
            $this->view_filter,
            $this->callers_active,
        );
    }

    public function withViewFilter(?string $filter): self
    {
        return new self(
            $this->mode,
            $this->focus_id,
            $this->focus_label,
            $filter,
            $this->callers_active,
        );
    }

    public function withCallersActive(bool $callers_active): self
    {
        return new self(
            $this->mode,
            $this->focus_id,
            $this->focus_label,
            $this->view_filter,
            $callers_active,
        );
    }
}
