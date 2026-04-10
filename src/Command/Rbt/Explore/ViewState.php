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
 * - $view         Aggregator::VIEW_* constant.
 * - $focus_id     Frame id (line- or no-line-space) the view is focused on,
 *                 or null for unfocused views (self/total top).
 * - $focus_label  Display label captured at focus time.
 * - $view_filter  Per-view PCRE applied to row labels at render time
 *                 (separate from the global match filter on ViewOptions).
 */
final class ViewState
{
    public function __construct(
        public readonly string $view,
        public readonly ?int $focus_id,
        public readonly ?string $focus_label,
        public readonly ?string $view_filter = null,
    ) {
    }

    public function withView(string $view): self
    {
        return new self($view, $this->focus_id, $this->focus_label, $this->view_filter);
    }

    public function withViewFilter(?string $filter): self
    {
        return new self($this->view, $this->focus_id, $this->focus_label, $filter);
    }
}
