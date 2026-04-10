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
 * Render loop and event dispatcher for `rbt:explore`.
 *
 * Two top-level layouts:
 *
 *   - **List mode** ({@see ExploreMode::ListSelf} / {@see ExploreMode::ListTotal})
 *     A single hot-frame table — the entry view, also reachable any time
 *     via the `s` / `t` keys. Pressing Enter on a row drops into Sandwich
 *     mode focused on that frame.
 *
 *   - **Sandwich mode** ({@see ExploreMode::Sandwich})
 *     Three sections: callers pane on top, focus banner in the middle,
 *     callees pane on the bottom. Each side pane has its own selection
 *     and scroll position; `Tab` toggles which side is active and ↑↓
 *     navigate that side. ←/→ explicitly pick callers or callees. `Enter`
 *     promotes the active pane's selected row to the new focus and keeps
 *     the same side active so the user can keep climbing in one direction.
 *     `u` pops focus history.
 */
final class ExploreTui
{
    private const MIN_ROWS = 12;
    private const MIN_COLS = 60;
    private const SYNTH_ROOT = -1;
    private const SYNTH_LEAF = -2;

    private TraceModel $model;
    private Terminal $term;
    private Keymap $keymap;

    /** @var list<ViewState> stack of focuses; top is current */
    private array $stack;

    private ViewOptions $opts;

    /**
     * Per-pane scroll state. Each entry is {selected, top_row}. Resets
     * to {0, 0} on any focus change so the user always starts at the top.
     */
    private int $list_selected = 0;
    private int $list_top_row = 0;
    private int $callers_selected = 0;
    private int $callers_top_row = 0;
    private int $callees_selected = 0;
    private int $callees_top_row = 0;
    private int $overview_selected = 0;
    private int $overview_top_row = 0;

    /**
     * Per-pane caches. Each cache is
     *   ['matched_samples' => int, 'rows' => list<array{int,int,string}>]
     * (count, key_id, label) — built lazily by {@see self::ensurePane()}.
     *
     * @var array{matched_samples:int, rows:list<array{int,int,string}>}|null
     */
    private ?array $list_cache = null;

    /** @var array{matched_samples:int, rows:list<array{int,int,string}>}|null */
    private ?array $callers_cache = null;

    /** @var array{matched_samples:int, rows:list<array{int,int,string}>}|null */
    private ?array $callees_cache = null;

    /**
     * Self-time or total-time top in no-line space, used by the overview
     * sidebar. Always grouped by function name regardless of $opts->no_line
     * so the sidebar shows a stable "where am I in the big picture" view.
     * Sort flavour is controlled by {@see self::$overview_sort}.
     *
     * @var array{matched_samples:int, rows:list<array{int,int,string}>}|null
     */
    private ?array $overview_cache = null;

    /**
     * Whether the overview pane is sorted by self-time or total-time.
     * Toggled with `s` / `t` while in sandwich mode.
     *
     * @var 'self'|'total'
     */
    private string $overview_sort = 'self';

    /**
     * When on, moving the overview cursor with ↑↓ also replaces the
     * current sandwich state's focus inline (no history push). Toggled
     * with `f`. Off by default to keep history "checkpoint" semantics.
     */
    private bool $overview_follow = false;

    /**
     * On by default — the Braille mini-flame strip is the most direct
     * "where am I in the global picture" affordance the explorer has.
     * Toggleable with `F` for users who'd rather get the body row back.
     */
    private bool $mini_flame_enabled = true;

    /**
     * Tri-state: null = auto (show on wide terminals), true = forced on,
     * false = forced off. Toggled with the `o` keybinding.
     */
    private ?bool $sidebar_override = null;

    private string $status = '';

    /** Modal text input state (filter prompts). */
    private ?string $prompt_label = null;
    private string $prompt_buffer = '';
    /** @var (callable(string): void)|null */
    private $prompt_commit = null;

    private bool $help_open = false;
    private bool $running = true;

    /**
     * Sandwich-flame popup overlay state.
     *
     * The popup shows a speedscope-style flame view (callers stacked
     * above the focus, callees stacked below) computed from the full
     * sample stacks rather than the 1-level aggregated callers/callees
     * tables. Built lazily on first open via {@see SandwichBuilder} and
     * cached until the focus / no-line / match filter changes.
     *
     * @var array{
     *   focus_count:int,
     *   callers:array<int,array{count:int,children:array}>,
     *   callees:array<int,array{count:int,children:array}>,
     * }|null
     */
    private ?array $sandwich_cache = null;
    private ?int $sandwich_cache_focus_id = null;
    private ?bool $sandwich_cache_no_line = null;
    private ?string $sandwich_cache_match_re = null;
    private bool $sandwich_popup_open = false;

    /**
     * Indented-tree popup direction. null = closed; 'callees' walks the
     * focus's callee tree (toward leaves), 'callers' walks the caller
     * tree (toward roots). Reuses {@see ensureSandwichTree} for data,
     * so opening either of these is free if the sandwich-flame popup
     * was already opened on the same focus (and vice versa).
     *
     * @var 'callees'|'callers'|null
     */
    private ?string $tree_popup_direction = null;

    public function __construct(TraceModel $model, Terminal $term, Keymap $keymap)
    {
        $this->model = $model;
        $this->term = $term;
        $this->keymap = $keymap;
        $this->opts = new ViewOptions();
        $this->stack = [
            new ViewState(mode: ExploreMode::ListSelf),
        ];
    }

    public function run(): void
    {
        $this->term->enter();
        try {
            while ($this->running) {
                $this->render();
                $key = $this->term->pollKey();
                if ($key === '') {
                    continue;
                }
                $this->dispatch($key);
            }
        } finally {
            $this->term->leave();
        }
    }

    // ---------- rendering ----------

    private function render(): void
    {
        [$cols, $rows] = $this->term->size();
        if ($cols < self::MIN_COLS || $rows < self::MIN_ROWS) {
            $this->term->clear();
            $this->term->write(sprintf(
                "Terminal too small: need >= %dx%d (have %dx%d)\n",
                self::MIN_COLS,
                self::MIN_ROWS,
                $cols,
                $rows,
            ));
            return;
        }

        $state = $this->currentState();

        // Body height is everything except header (4 lines), footer (1),
        // and status (1) — 6 fixed lines. The mini-flame strip is
        // opt-in (toggle with `F`) and only meaningful in sandwich mode;
        // when on, it consumes one extra line.
        $show_mini_flame = $state->mode === ExploreMode::Sandwich
            && $this->mini_flame_enabled;
        $body_rows = $rows - 6 - ($show_mini_flame ? 1 : 0);
        if ($body_rows < 3) {
            $body_rows = 3;
        }

        // Sidebar is only shown for sandwich (the mode that benefits
        // from a "you are here" overview); the list views already are
        // the overview.
        $show_sidebar = $state->mode === ExploreMode::Sandwich
            && $this->shouldShowSidebar($cols);
        $sidebar_width = $show_sidebar ? $this->computeSidebarWidth($cols) : 0;
        $separator_width = $show_sidebar ? 1 : 0;
        $body_width = $cols - $sidebar_width - $separator_width;

        $buf = "\e[H\e[2J"; // home + clear
        $buf .= $this->renderHeader($state, $cols);

        if ($state->mode === ExploreMode::Sandwich) {
            if ($show_mini_flame) {
                $buf .= $this->renderMiniFlame($cols) . "\n";
            }
            $body_lines = $this->renderSandwichLines($state, $body_width, $body_rows);
        } else {
            $body_lines = $this->renderListLines($state, $body_width, $body_rows);
        }

        if ($show_sidebar) {
            $sidebar_lines = $this->renderOverviewLines($sidebar_width, $body_rows);
            for ($i = 0; $i < $body_rows; $i++) {
                $sl = $sidebar_lines[$i] ?? str_repeat(' ', $sidebar_width);
                $bl = $body_lines[$i] ?? '';
                $buf .= $sl . "\e[2m│\e[22m" . $bl . "\n";
            }
        } else {
            for ($i = 0; $i < $body_rows; $i++) {
                $buf .= ($body_lines[$i] ?? '') . "\n";
            }
        }

        $buf .= $this->renderFooter($state, $cols);
        $buf .= $this->renderStatus($state, $cols);

        if ($this->sandwich_popup_open) {
            $buf .= $this->renderSandwichFlameOverlay($cols, $rows);
        }
        if ($this->tree_popup_direction !== null) {
            $buf .= $this->renderTreePopupOverlay(
                $cols,
                $rows,
                $this->tree_popup_direction,
            );
        }
        if ($this->help_open) {
            $buf .= $this->renderHelpOverlay($cols, $rows);
        }

        $this->term->write($buf);
    }

    /**
     * 1-line horizontal "mini flame" rendered as a Braille subpixel
     * histogram. Each Braille cell encodes 2 horizontal sub-pixels
     * (`width=80` → 160 subpixels), so even small frames stay visible
     * as a thin half-cell line instead of disappearing entirely.
     *
     * Adjacent frames alternate dim/bright so block boundaries pop
     * without needing labels. The pixels of whichever frame is under
     * the active pane's cursor right now are rendered in reverse video,
     * so as the user navigates ↑↓ the strip continuously says "this
     * row is THIS big in the global picture" — useful for the "is
     * this big enough to be worth a detour?" decision.
     *
     * No text inside the strip — labels live in the sidebar.
     */
    private function renderMiniFlame(int $width): string
    {
        $cache = $this->ensureOverview();
        $rows = $cache['rows'];
        if ($rows === [] || $width <= 0) {
            return str_repeat(' ', max(0, $width));
        }

        $total = 0;
        foreach ($rows as [$count, , ]) {
            $total += $count;
        }
        if ($total === 0) {
            return str_repeat(' ', $width);
        }

        $highlight_id = $this->getCursorKeyId() ?? $this->getCurrentFocusKeyId();

        // 2 horizontal sub-pixels per terminal cell.
        $total_pixels = $width * 2;
        /** @var list<int> pixel_index → frame_index, -1 = unallocated */
        $pixel_frame = array_fill(0, $total_pixels, -1);
        /** @var list<bool> pixel_index → is_focus */
        $pixel_focus = array_fill(0, $total_pixels, false);

        // Pre-compute the cursor row's index in the sorted overview so
        // we know whether the natural layout will reach it before
        // running out of pixels.
        $cursor_row_idx = null;
        if ($highlight_id !== null) {
            foreach ($rows as $i => [, $key_id, ]) {
                if ($key_id === $highlight_id) {
                    $cursor_row_idx = $i;
                    break;
                }
            }
        }

        // Allocate widths in *virtual* (unbounded) pixel space first so
        // we can compute the cursor row's natural position even when it
        // ends up beyond the visible strip.
        $virtual_widths = [];
        $virtual_total = 0;
        foreach ($rows as $i => [$count, , ]) {
            $w = (int)round($count / $total * $total_pixels);
            if ($w === 0 && $count > 0) {
                $w = 1;
            }
            $virtual_widths[$i] = $w;
            $virtual_total += $w;
        }

        // Walk frames in order, allocating subpixels proportionally.
        // The break at $total_pixels truncates the long tail.
        $pos = 0;
        $cursor_rendered_in_strip = false;
        foreach ($rows as $i => [, $key_id, ]) {
            if ($pos >= $total_pixels) {
                break;
            }
            $w = $virtual_widths[$i];
            if ($w === 0) {
                continue;
            }
            $end = min($pos + $w, $total_pixels);
            $is_highlight = $i === $cursor_row_idx;
            for ($p = $pos; $p < $end; $p++) {
                $pixel_frame[$p] = $i;
                $pixel_focus[$p] = $is_highlight;
            }
            if ($is_highlight) {
                $cursor_rendered_in_strip = true;
            }
            $pos = $end;
        }

        // If the cursor row's natural position lies past the visible
        // strip (typical for callers/callees on aggregator functions
        // with low self-time), drop a single-pixel marker at the
        // rank-proportional position so the highlight at least moves
        // as the user navigates instead of staying glued to the focus.
        if ($cursor_row_idx !== null && !$cursor_rendered_in_strip && $virtual_total > 0) {
            $virtual_start = 0;
            for ($i = 0; $i < $cursor_row_idx; $i++) {
                $virtual_start += $virtual_widths[$i] ?? 0;
            }
            $marker_pixel = (int)floor($virtual_start / $virtual_total * $total_pixels);
            if ($marker_pixel >= $total_pixels) {
                $marker_pixel = $total_pixels - 1;
            }
            if ($marker_pixel < 0) {
                $marker_pixel = 0;
            }
            $pixel_focus[$marker_pixel] = true;
        }

        // Render cells. State (focus, dim) is sticky across cells; only
        // emit ANSI sequences when it actually changes.
        $line = '';
        $current_focus = false;
        $current_dim = false;
        for ($c = 0; $c < $width; $c++) {
            $left_p = $c * 2;
            $right_p = $left_p + 1;
            $left_f = $pixel_frame[$left_p];
            $right_f = $pixel_frame[$right_p];

            if ($left_f >= 0 && $right_f >= 0) {
                $char = '⣿';
            } elseif ($left_f >= 0) {
                $char = '⡇';
            } elseif ($right_f >= 0) {
                $char = '⢸';
            } else {
                $char = ' ';
            }

            $primary_f = $left_f >= 0 ? $left_f : $right_f;
            $is_dim = $primary_f >= 0 && ($primary_f % 2 === 1);
            $is_focus = $pixel_focus[$left_p] || $pixel_focus[$right_p];

            if ($is_focus !== $current_focus || $is_dim !== $current_dim) {
                $line .= "\e[0m";
                if ($is_focus) {
                    $line .= "\e[7m";
                }
                if ($is_dim) {
                    $line .= "\e[2m";
                }
                $current_focus = $is_focus;
                $current_dim = $is_dim;
            }
            $line .= $char;
        }
        if ($current_focus || $current_dim) {
            $line .= "\e[0m";
        }

        return $line;
    }

    private function shouldShowSidebar(int $cols): bool
    {
        // Hard floor: a sidebar on a 80-col terminal would crush both
        // panes to nothing. 100 cols is the minimum where it pays.
        if ($cols < 100) {
            return false;
        }
        if ($this->sidebar_override !== null) {
            return $this->sidebar_override;
        }
        return $cols >= 120;
    }

    private function computeSidebarWidth(int $cols): int
    {
        // Adaptive: ~25% of width, clamped to a useful range so labels
        // get enough room without starving the sandwich panes.
        $width = (int)($cols * 0.28);
        if ($width < 30) {
            $width = 30;
        }
        if ($width > 50) {
            $width = 50;
        }
        return $width;
    }

    private function renderHeader(ViewState $state, int $cols): string
    {
        $line1 = sprintf(
            'rbt:explore — %s (%s samples · %.1fs · %dus period)',
            self::shorten(basename($this->model->source_path), $cols - 4),
            number_format($this->model->sampleCount()),
            $this->model->durationSeconds(),
            $this->model->sampling_period_us,
        );
        $hist = count($this->stack) - 1;
        $mode_label = match ($state->mode) {
            ExploreMode::ListSelf  => 'self-time top',
            ExploreMode::ListTotal => 'total-time top',
            ExploreMode::Sandwich  => 'sandwich (focus drilldown)',
        };
        $line2 = sprintf('mode: %s   history: %d back', $mode_label, $hist);
        $line3 = sprintf(
            'no-line: %s   match: %s   filter: %s',
            $this->opts->no_line ? 'on' : 'off',
            $this->opts->match_re ?? '(none)',
            $state->view_filter ?? '(none)',
        );

        $out = $this->styleHeader(self::shorten($line1, $cols)) . "\n";
        $out .= self::shorten($line2, $cols) . "\n";
        $out .= self::shorten($line3, $cols) . "\n";
        $out .= str_repeat('─', $cols) . "\n";
        return $out;
    }

    /**
     * @return list<string> exactly $body_rows lines, padded as needed
     */
    private function renderListLines(ViewState $state, int $width, int $body_rows): array
    {
        $cache = $this->ensureList();
        $rows_data = $this->applyViewFilter($cache['rows'], $state->view_filter);
        $denom = max(1, $cache['matched_samples']);
        $max_count = max(1, self::maxRowCount($rows_data));

        $this->clampSelection($this->list_selected, $this->list_top_row, count($rows_data), $body_rows - 1);

        $out = [];
        $out[] = $this->styleTableHeader(self::padOrShorten(
            sprintf('   %9s  %6s  %s', 'count', 'pct', 'frame'),
            $width,
        ));

        $visible = $body_rows - 1;
        for ($i = 0; $i < $visible; $i++) {
            $idx = $this->list_top_row + $i;
            if (!isset($rows_data[$idx])) {
                $out[] = str_repeat(' ', $width);
                continue;
            }
            [$count, , $label] = $rows_data[$idx];
            $pct = ($count / $denom) * 100;
            $marker = $idx === $this->list_selected ? '>' : ' ';
            $bar = self::barChar($count / $max_count);
            $line = self::padOrShorten(
                sprintf('%s%s %9s  %5.1f%%  %s', $marker, $bar, number_format($count), $pct, $label),
                $width,
            );
            if ($idx === $this->list_selected) {
                $line = "\e[7m" . $line . "\e[27m";
            }
            $out[] = $line;
        }
        return $out;
    }

    /**
     * @return list<string> exactly $body_rows lines, padded as needed
     */
    private function renderSandwichLines(ViewState $state, int $width, int $body_rows): array
    {
        $callers = $this->ensureCallers();
        $callees = $this->ensureCallees();
        $caller_rows = $this->applyViewFilter($callers['rows'], $state->view_filter);
        $callee_rows = $this->applyViewFilter($callees['rows'], $state->view_filter);
        $caller_denom = max(1, $callers['matched_samples']);
        $callee_denom = max(1, $callees['matched_samples']);

        // Layout: focus banner = 3 lines (top border, label, bottom border).
        // Each pane gets a header line + body. Split remaining body_rows
        // evenly between callers (top) and callees (bottom).
        $banner_rows = 3;
        $available = max(0, $body_rows - $banner_rows);
        $caller_body = (int)floor($available / 2);
        $callee_body = $available - $caller_body;
        $caller_visible = max(0, $caller_body - 1);
        $callee_visible = max(0, $callee_body - 1);

        $this->clampSelection($this->callers_selected, $this->callers_top_row, count($caller_rows), $caller_visible);
        $this->clampSelection($this->callees_selected, $this->callees_top_row, count($callee_rows), $callee_visible);

        $caller_lines = $this->renderPaneLines(
            'callers',
            $caller_rows,
            $caller_denom,
            $this->callers_selected,
            $this->callers_top_row,
            $caller_visible,
            $width,
            $state->active_pane === ActivePane::Callers,
        );
        $banner_lines = $this->renderFocusBannerLines($state, $width);
        $callee_lines = $this->renderPaneLines(
            'callees',
            $callee_rows,
            $callee_denom,
            $this->callees_selected,
            $this->callees_top_row,
            $callee_visible,
            $width,
            $state->active_pane === ActivePane::Callees,
        );

        $out = [];
        foreach ($caller_lines as $line) {
            $out[] = $line;
        }
        foreach ($banner_lines as $line) {
            $out[] = $line;
        }
        foreach ($callee_lines as $line) {
            $out[] = $line;
        }
        // Pad to body_rows if we somehow came up short.
        while (count($out) < $body_rows) {
            $out[] = str_repeat(' ', $width);
        }
        return $out;
    }

    /**
     * @param  list<array{int, int, string}> $rows
     * @return list<string>                  pane header + visible rows
     */
    private function renderPaneLines(
        string $title,
        array $rows,
        int $denom,
        int $selected,
        int $top_row,
        int $visible,
        int $width,
        bool $active,
    ): array {
        $marker = $active ? '▶' : ' ';
        $count_label = sprintf('%s (%d)', $title, count($rows));
        $header = sprintf('%s ── %s ', $marker, $count_label);
        $pad_len = max(0, $width - mb_strlen($header));
        $header .= str_repeat('─', $pad_len);
        $header = self::padOrShorten($header, $width);
        $header = $active
            ? "\e[1m" . $header . "\e[22m"
            : "\e[2m" . $header . "\e[22m";
        $out = [$header];

        if ($visible === 0) {
            return $out;
        }
        if ($rows === []) {
            $out[] = self::padOrShorten('  (no entries)', $width);
            for ($i = 1; $i < $visible; $i++) {
                $out[] = str_repeat(' ', $width);
            }
            return $out;
        }

        $max_count = max(1, self::maxRowCount($rows));
        for ($i = 0; $i < $visible; $i++) {
            $idx = $top_row + $i;
            if (!isset($rows[$idx])) {
                $out[] = str_repeat(' ', $width);
                continue;
            }
            [$count, , $label] = $rows[$idx];
            $pct = ($count / $denom) * 100;
            $is_sel = $active && $idx === $selected;
            $sel_mark = $is_sel ? '>' : ' ';
            $bar = self::barChar($count / $max_count);
            $line = self::padOrShorten(
                sprintf('%s%s %9s  %5.1f%%  %s', $sel_mark, $bar, number_format($count), $pct, $label),
                $width,
            );
            if ($is_sel) {
                $line = "\e[7m" . $line . "\e[27m";
            }
            $out[] = $line;
        }
        return $out;
    }

    /**
     * @return list<string> three lines: top border, content, bottom border
     */
    private function renderFocusBannerLines(ViewState $state, int $width): array
    {
        $label = $state->focus_label ?? '<none>';
        $stats = $this->lookupFocusSelfStats($state);
        $stats_text = $stats !== null
            ? sprintf(' — %s (%5.1f%%)', number_format($stats['count']), $stats['pct'])
            : '';

        $top = '┌' . str_repeat('─', max(0, $width - 2)) . '┐';
        $bottom = '└' . str_repeat('─', max(0, $width - 2)) . '┘';

        $left = '│ focus: ';
        $right = $stats_text . ' │';
        $label_room = max(0, $width - mb_strlen($left) - mb_strlen($right));
        $label_short = self::shorten($label, $label_room);
        $inner = $left . $label_short;
        $padding = max(0, $width - mb_strlen($inner) - mb_strlen($right));
        $inner .= str_repeat(' ', $padding) . $right;
        $inner = self::padOrShorten($inner, $width);

        return [
            "\e[1m" . self::padOrShorten($top, $width) . "\e[22m",
            "\e[1m" . $inner . "\e[22m",
            "\e[1m" . self::padOrShorten($bottom, $width) . "\e[22m",
        ];
    }

    /**
     * Look up the focus's self-time count and pct of total samples.
     *
     * @return array{count:int, pct:float}|null
     */
    private function lookupFocusSelfStats(ViewState $state): ?array
    {
        if ($state->focus_id === null) {
            return null;
        }
        // Find a row in the list cache (= self/total top with current opts)
        // whose key_id matches the focus.
        $cache = $this->ensureList();
        foreach ($cache['rows'] as [$count, $key_id, $label]) {
            if ($key_id === $state->focus_id) {
                $denom = max(1, $cache['matched_samples']);
                return ['count' => $count, 'pct' => $count / $denom * 100];
            }
        }
        return null;
    }

    /**
     * @return list<string> exactly $body_rows lines for the left sidebar
     */
    private function renderOverviewLines(int $width, int $body_rows): array
    {
        $cache = $this->ensureOverview();
        $rows = $cache['rows'];
        $denom = max(1, $cache['matched_samples']);
        $focus_no_line_id = $this->getCurrentFocusKeyId();

        $state = $this->currentState();
        $is_active = $state->mode === ExploreMode::Sandwich
            && $state->active_pane === ActivePane::Overview;

        // Find the row matching the current focus (for the diamond marker).
        $focus_idx = null;
        if ($focus_no_line_id !== null) {
            foreach ($rows as $i => [, $key_id, ]) {
                if ($key_id === $focus_no_line_id) {
                    $focus_idx = $i;
                    break;
                }
            }
        }
        $visible = max(0, $body_rows - 1); // header takes 1 line

        // When the overview is active, the user is driving scroll manually.
        // When not active, auto-scroll keeps the focus row centered so the
        // sidebar always shows "you are here".
        if ($is_active) {
            $this->clampSelection(
                $this->overview_selected,
                $this->overview_top_row,
                count($rows),
                $visible,
            );
            $top_row = $this->overview_top_row;
        } else {
            $top_row = 0;
            if ($focus_idx !== null && $visible > 0) {
                $top_row = max(0, $focus_idx - intdiv($visible, 2));
                $top_row = min($top_row, max(0, count($rows) - $visible));
            }
            // Mirror the auto-scroll position into overview_top_row so the
            // first time the user activates this pane the selection lands
            // somewhere visible (and ideally on the focus row).
            $this->overview_top_row = $top_row;
            if ($focus_idx !== null) {
                $this->overview_selected = $focus_idx;
            }
        }

        $marker_active = $is_active ? '▶' : ' ';
        $sort_label = $this->overview_sort;
        $title = $is_active
            ? sprintf('%s ── overview (%s) ──', $marker_active, $sort_label)
            : sprintf('── overview (%s) ──', $sort_label);
        $header_line = self::padOrShorten($title, $width);
        $out = [
            $is_active
                ? "\e[1m" . $header_line . "\e[22m"
                : "\e[2m" . $header_line . "\e[22m",
        ];

        $max_count = max(1, self::maxRowCount($rows));
        for ($i = 0; $i < $visible; $i++) {
            $idx = $top_row + $i;
            if (!isset($rows[$idx])) {
                $out[] = str_repeat(' ', $width);
                continue;
            }
            [$count, $key_id, $label] = $rows[$idx];
            $pct = ($count / $denom) * 100;
            $is_focus = $focus_idx !== null && $idx === $focus_idx;
            $is_sel = $is_active && $idx === $this->overview_selected;
            // Selection cursor takes precedence over the focus marker
            // since "what I'm pointing at" matters more during navigation.
            $marker = $is_sel
                ? '>'
                : ($is_focus ? '◆' : ' ');
            $bar = self::barChar($count / $max_count);
            // marker(1) + bar(1) + space(1) + pct(5) + space(1) = 9 overhead
            $label_room = max(1, $width - 9);
            $line = sprintf(
                '%s%s %s %5.1f%%',
                $marker,
                $bar,
                self::padOrShorten($label, $label_room),
                $pct,
            );
            $line = self::padOrShorten($line, $width);
            if ($is_sel) {
                $line = "\e[7m" . $line . "\e[27m";
            } elseif ($is_focus) {
                // Bold the focus row even when not active so the eye can
                // find it instantly.
                $line = "\e[1m" . $line . "\e[22m";
            }
            $out[] = $line;
        }
        return $out;
    }

    /**
     * Switch the overview pane between self-time and total-time sort.
     * No-op when the requested sort already matches; otherwise the
     * cache is busted and the cursor returns to the top because the
     * row order has changed under it.
     *
     * @param 'self'|'total' $sort
     */
    private function setOverviewSort(string $sort): void
    {
        if ($this->overview_sort === $sort) {
            $this->status = "overview: {$sort}-time (already)";
            return;
        }
        $this->overview_sort = $sort;
        $this->overview_cache = null;
        $this->overview_selected = 0;
        $this->overview_top_row = 0;
        $this->status = "overview: {$sort}-time";
    }

    private function getCurrentFocusKeyId(): ?int
    {
        // The overview now follows the global no-line setting, so its
        // row keys live in the same id space as the focus_id we have.
        // No projection needed.
        return $this->currentState()->focus_id;
    }

    /**
     * Key id under the active pane's cursor right now (not the focus).
     * Used to drive the mini-flame highlight so the strip shows
     * "how big is the row I'm currently hovering on" while the user
     * navigates, separately from where they've actually drilled in.
     */
    private function getCursorKeyId(): ?int
    {
        $state = $this->currentState();
        if ($state->mode !== ExploreMode::Sandwich) {
            return null;
        }
        switch ($state->active_pane) {
            case ActivePane::Callers:
                $rows = $this->applyViewFilter(
                    $this->ensureCallers()['rows'],
                    $state->view_filter,
                );
                $idx = $this->callers_selected;
                break;
            case ActivePane::Callees:
                $rows = $this->applyViewFilter(
                    $this->ensureCallees()['rows'],
                    $state->view_filter,
                );
                $idx = $this->callees_selected;
                break;
            case ActivePane::Overview:
                $rows = $this->ensureOverview()['rows'];
                $idx = $this->overview_selected;
                break;
        }
        if (!isset($rows[$idx])) {
            return null;
        }
        [, $key_id, ] = $rows[$idx];
        return $key_id < 0 ? null : $key_id;
    }

    private function renderFooter(ViewState $state, int $cols): string
    {
        if ($state->mode === ExploreMode::Sandwich) {
            $follow = $this->overview_follow ? '[f*]' : '[f]';
            $flame = $this->mini_flame_enabled ? '[F*]' : '[F]';
            $footer = '[Tab/⇧Tab] cycle pane  [↑↓] sel  [Enter] focus  [←/→] callers/callees  '
                . '[u] back  [s/t/O] self/total/overview  ' . $follow . ' follow  '
                . $flame . ' flame  [/] filter  [m] match  [n] no-line  [o] sidebar  '
                . '[?] help  [q] quit';
        } else {
            $footer = '[↑↓] sel  [Enter] focus  [s/t/O] self/total/overview  '
                . '[/] filter  [m] match  [n] no-line  [?] help  [q] quit';
        }
        return self::shorten($footer, $cols) . "\n";
    }

    private function renderStatus(ViewState $state, int $cols): string
    {
        if ($this->prompt_label !== null) {
            return self::shorten("{$this->prompt_label}{$this->prompt_buffer}_", $cols);
        }
        if ($this->status !== '') {
            return self::shorten($this->status, $cols);
        }
        if ($state->mode === ExploreMode::Sandwich) {
            $callers = $this->ensureCallers();
            $callees = $this->ensureCallees();
            return self::shorten(sprintf(
                'callers matched %s / callees matched %s samples',
                number_format($callers['matched_samples']),
                number_format($callees['matched_samples']),
            ), $cols);
        }
        $cache = $this->ensureList();
        return self::shorten(sprintf(
            '%d rows · matched %s / %s samples',
            count($cache['rows']),
            number_format($cache['matched_samples']),
            number_format($this->model->sampleCount()),
        ), $cols);
    }

    private function styleHeader(string $s): string
    {
        return "\e[1m" . $s . "\e[22m";
    }

    private function styleTableHeader(string $s): string
    {
        return "\e[2m" . $s . "\e[22m";
    }

    private function renderHelpOverlay(int $cols, int $rows): string
    {
        $lines = [
            '── help ──────────────────────────────',
            '  ↑ / k        select previous',
            '  ↓ / j        select next',
            '  PgUp / PgDn  page up / down',
            '  g / G        first / last row',
            '  Enter        focus selected row → sandwich view',
            '  Tab          cycle active pane: callers → callees → overview',
            '  Shift+Tab    cycle the other way',
            '  ← / h        focus callers pane',
            '  → / l        focus callees pane',
            '  u / Bksp     pop focus history (also restores overview cursor)',
            '  f            toggle overview live-follow (cursor = focus)',
            '  s            sandwich: focus overview, sort by self-time',
            '               list:     switch to self-time list',
            '  t            sandwich: focus overview, sort by total-time',
            '               list:     switch to total-time list',
            '  O            fullscreen overview (no-line self top)',
            '  /            filter visible rows (PCRE)',
            '  m            global sample match (PCRE)',
            '  n            toggle no-line grouping',
            '  o            toggle overview sidebar',
            '  F            toggle horizontal mini-flame strip',
            '  S            sandwich-flame popup (callers + callees) at focus',
            '  >            callee tree popup (% bars + full labels)',
            '  <            caller tree popup (% bars + full labels)',
            '  ?            this help',
            '  q / Ctrl-C   quit',
            '── press any key ──',
        ];
        $width = 0;
        foreach ($lines as $l) {
            $w = mb_strlen($l);
            if ($w > $width) {
                $width = $w;
            }
        }
        $start_col = max(1, (int)(($cols - $width - 4) / 2));
        $start_row = max(1, (int)(($rows - count($lines) - 2) / 2));
        $out = '';
        foreach ($lines as $i => $l) {
            $padding = str_repeat(' ', $width - mb_strlen($l));
            $out .= sprintf(
                "\e[%d;%dH\e[7m %s%s \e[27m",
                $start_row + $i,
                $start_col,
                $l,
                $padding,
            );
        }
        return $out;
    }

    /**
     * Full-screen flame-chart overlay rooted at the current focus.
     *
     * Layout: a horizontal title bar at the top, the caller tree growing
     * upward from the focus row, the focus bar in the middle, the callee
     * tree growing downward, and a footer hint at the bottom. Bar widths
     * are proportional to sample counts of the focus, so a child taking
     * half its parent's bar means half of the samples that flowed
     * through the parent then went into that child.
     *
     * Rendered with ANSI cursor positioning so it overlays whatever the
     * normal body painted (each cell is filled, no holes).
     */
    private function renderSandwichFlameOverlay(int $cols, int $rows): string
    {
        $tree = $this->ensureSandwichTree();
        if ($tree === null) {
            return '';
        }
        $state = $this->currentState();
        if ($cols < 30 || $rows < 8) {
            return '';
        }

        // 1 row title + 1 row focus + 1 row footer = 3 reserved.
        $tree_rows = $rows - 3;
        if ($tree_rows < 2) {
            return '';
        }
        $caller_rows = (int)floor($tree_rows / 2);
        $callee_rows = $tree_rows - $caller_rows;
        $inner_w = $cols;
        $focus_count = max(1, $tree['focus_count']);

        $caller_layout = self::layoutFlameTree(
            $tree['callers'],
            $focus_count,
            $inner_w,
            $caller_rows,
        );
        $callee_layout = self::layoutFlameTree(
            $tree['callees'],
            $focus_count,
            $inner_w,
            $callee_rows,
        );

        $no_line = $this->opts->no_line;
        $lines = [];

        $title = sprintf(
            ' sandwich flame — %s   (%s samples)   [any key closes] ',
            $state->focus_label ?? '<none>',
            number_format($focus_count),
        );
        $lines[] = "\e[1;7m" . self::padOrShorten($title, $inner_w) . "\e[0m";

        // Caller depths: top of popup is the deepest visible depth so the
        // user reads downward toward the focus row, the way a stack
        // naturally piles up from root to leaf.
        for ($vis = 0; $vis < $caller_rows; $vis++) {
            $depth = $caller_rows - $vis;
            $bars = $caller_layout[$depth] ?? [];
            $lines[] = $this->renderFlameRow($bars, $inner_w, $no_line);
        }

        // Focus bar — distinct from regular bars so the eye anchors on it.
        $focus_text = ' ▶ ' . ($state->focus_label ?? '<none>');
        $lines[] = "\e[1;7;33m" . self::padOrShorten($focus_text, $inner_w) . "\e[0m";

        // Callee depths: depth 1 directly under the focus, deeper farther
        // down — same "stack flowing toward leaf" reading order.
        for ($vis = 0; $vis < $callee_rows; $vis++) {
            $depth = $vis + 1;
            $bars = $callee_layout[$depth] ?? [];
            $lines[] = $this->renderFlameRow($bars, $inner_w, $no_line);
        }

        $footer = ' callers above · callees below · widths ∝ samples through focus ';
        $lines[] = "\e[2;7m" . self::padOrShorten($footer, $inner_w) . "\e[0m";

        $out = '';
        foreach ($lines as $i => $line) {
            $out .= sprintf("\e[%d;1H", $i + 1) . $line;
        }
        return $out;
    }

    /**
     * Render one row of the flame chart. Bars are drawn in reverse
     * video with alternating dim/bright so adjacent frames stay visually
     * distinct. Labels are written into bars wide enough to hold them
     * (≥ 4 cells); narrower bars become anonymous solid stripes you can
     * still navigate to via overview/sandwich panes.
     *
     * @param list<array{x:int, w:int, key_id:int, count:int}> $bars
     */
    private function renderFlameRow(array $bars, int $width, bool $no_line): string
    {
        if ($bars === []) {
            return str_repeat(' ', $width);
        }
        usort($bars, fn(array $a, array $b): int => $a['x'] <=> $b['x']);

        $out = '';
        $cur = 0;
        foreach ($bars as $i => $bar) {
            if ($bar['w'] < 1) {
                continue;
            }
            if ($cur < $bar['x']) {
                $out .= str_repeat(' ', $bar['x'] - $cur);
                $cur = $bar['x'];
            }
            $w = $bar['w'];
            if ($w >= 4) {
                $label = Aggregator::labelFor($this->model, $bar['key_id'], $no_line);
                // Strip "file:line" tail when present so the function
                // name fits even in cramped bars.
                $space_pos = strpos($label, ' ');
                $short = $space_pos !== false
                    ? substr($label, 0, $space_pos)
                    : $label;
                $cell = ' ' . self::shorten($short, $w - 1);
            } else {
                $cell = '';
            }
            $cell = self::padOrShorten($cell, $w);
            $style = ($i % 2) === 1 ? "\e[7;2m" : "\e[7m";
            $out .= $style . $cell . "\e[0m";
            $cur += $w;
        }
        if ($cur < $width) {
            $out .= str_repeat(' ', $width - $cur);
        }
        return $out;
    }

    /**
     * Recursive layout pass. Walks the sandwich tree top-down and
     * allocates each child a width proportional to (child.count /
     * parent.count) of the parent's width. Children whose width
     * rounds to zero are dropped — they'd be invisible anyway and
     * stealing a pixel from a larger sibling would distort the chart.
     *
     * @param  array<int, array{count:int, children:array}> $nodes
     * @return array<int, list<array{x:int, w:int, key_id:int, count:int}>>
     */
    private static function layoutFlameTree(
        array $nodes,
        int $denom,
        int $width,
        int $max_depth,
    ): array {
        /** @var array<int, list<array{x:int, w:int, key_id:int, count:int}>> $rows */
        $rows = [];
        self::layoutFlameNodes($nodes, 1, 0, $width, $denom, $max_depth, $rows);
        return $rows;
    }

    /**
     * @param array<int, array{count:int, children:array}>                  $nodes
     * @param array<int, list<array{x:int, w:int, key_id:int, count:int}>>  $rows
     */
    private static function layoutFlameNodes(
        array $nodes,
        int $depth,
        int $x,
        int $width,
        int $denom,
        int $max_depth,
        array &$rows,
    ): void {
        if ($depth > $max_depth || $width < 1 || $denom <= 0 || $nodes === []) {
            return;
        }
        // Largest first so the eye lands on the dominant call path.
        uasort(
            $nodes,
            /** @param array{count:int, children:array} $a
             *  @param array{count:int, children:array} $b */
            fn(array $a, array $b): int => $b['count'] <=> $a['count'],
        );

        $cur_x = $x;
        $remaining = $width;
        foreach ($nodes as $kid => $node) {
            if ($remaining < 1) {
                break;
            }
            $w = (int)floor($node['count'] / $denom * $width);
            if ($w < 1) {
                continue;
            }
            if ($w > $remaining) {
                $w = $remaining;
            }
            if (!isset($rows[$depth])) {
                $rows[$depth] = [];
            }
            $rows[$depth][] = [
                'x' => $cur_x,
                'w' => $w,
                'key_id' => $kid,
                'count' => $node['count'],
            ];
            // Recurse with this node as the new root: child widths are
            // relative to ITS count and footprint, which is the property
            // that gives flame charts their nesting semantics.
            self::layoutFlameNodes(
                $node['children'],
                $depth + 1,
                $cur_x,
                $w,
                $node['count'],
                $max_depth,
                $rows,
            );
            $cur_x += $w;
            $remaining -= $w;
        }
    }

    /**
     * Indented-tree popup overlay rooted at the current focus.
     *
     * The flame popup is great for "where is the mass" but loses
     * frame names in narrow bars; this view is the inverse — full,
     * readable labels at the cost of needing to scroll vertically
     * to see the long tail. Each row carries a Braille percentage bar
     * and the share-of-focus number, so the relative size of nested
     * frames is still legible at a glance.
     *
     * Reuses {@see ensureSandwichTree} so opening this after the
     * S popup (or vice versa) on the same focus is free.
     *
     * @param 'callees'|'callers' $direction
     */
    private function renderTreePopupOverlay(int $cols, int $rows, string $direction): string
    {
        $tree = $this->ensureSandwichTree();
        if ($tree === null) {
            return '';
        }
        $state = $this->currentState();
        if ($cols < 30 || $rows < 6) {
            return '';
        }

        $focus_count = max(1, $tree['focus_count']);
        $children = $direction === 'callees' ? $tree['callees'] : $tree['callers'];

        $title = sprintf(
            ' %s tree — %s   (%s samples)   [any key closes] ',
            $direction === 'callees' ? 'callee' : 'caller',
            $state->focus_label ?? '<none>',
            number_format($focus_count),
        );

        $lines = [];
        $lines[] = "\e[1;7m" . self::padOrShorten($title, $cols) . "\e[0m";

        // 2 rows reserved (title + footer); the rest are tree rows.
        $body_rows = $rows - 2;
        if ($body_rows < 1) {
            $body_rows = 1;
        }

        $tree_lines = [];
        $this->walkTreeForOverlay(
            $children,
            $focus_count,
            '',
            $cols,
            $body_rows,
            $tree_lines,
            $this->opts->no_line,
        );

        for ($i = 0; $i < $body_rows; $i++) {
            $lines[] = self::padOrShorten($tree_lines[$i] ?? '', $cols);
        }

        $footer = $direction === 'callees'
            ? ' walks toward leaves · indent = call depth · % is share of focus '
            : ' walks toward roots · indent = ancestry depth · % is share of focus ';
        $lines[] = "\e[2;7m" . self::padOrShorten($footer, $cols) . "\e[0m";

        $out = '';
        foreach ($lines as $i => $line) {
            $out .= sprintf("\e[%d;1H", $i + 1) . $line;
        }
        return $out;
    }

    /**
     * DFS-walk the sandwich tree producing one indented line per node.
     * Stops once $max_lines lines have been emitted (the popup just
     * truncates the long tail; v1 has no scrolling).
     *
     * Each row layout:
     *   {prefix}{connector}{braille_bar} {pct%} {label} ({count})
     *
     * @param array<int, array{count:int, children:array}> $nodes
     * @param list<string>                                 $lines
     */
    private function walkTreeForOverlay(
        array $nodes,
        int $denom,
        string $prefix,
        int $width,
        int $max_lines,
        array &$lines,
        bool $no_line,
    ): void {
        if ($nodes === [] || count($lines) >= $max_lines) {
            return;
        }
        // Largest first so the dominant branch is the first thing the
        // user reads on each level — same convention the flame popup
        // and overview both use.
        uasort(
            $nodes,
            /** @param array{count:int, children:array} $a
             *  @param array{count:int, children:array} $b */
            fn(array $a, array $b): int => $b['count'] <=> $a['count'],
        );

        $entries = array_keys($nodes);
        $n = count($entries);
        for ($i = 0; $i < $n; $i++) {
            if (count($lines) >= $max_lines) {
                return;
            }
            $kid = $entries[$i];
            /** @var array{count:int, children:array} $node */
            $node = $nodes[$kid];
            $is_last = ($i === $n - 1);
            $connector = $is_last ? '└─ ' : '├─ ';

            $ratio = $node['count'] / $denom;
            $bar = self::brailleHBar($ratio, 6);
            $label = Aggregator::labelFor($this->model, $kid, $no_line);

            $head = sprintf(
                '%s%s%s %5.1f%% ',
                $prefix,
                $connector,
                $bar,
                $ratio * 100.0,
            );
            $tail = sprintf(' (%s)', number_format($node['count']));
            $room = max(1, $width - mb_strlen($head) - mb_strlen($tail));
            $line = $head . self::shorten($label, $room) . $tail;
            $lines[] = $line;

            $next_prefix = $prefix . ($is_last ? '   ' : '│  ');
            $this->walkTreeForOverlay(
                $node['children'],
                $denom,
                $next_prefix,
                $width,
                $max_lines,
                $lines,
                $no_line,
            );
        }
    }

    /**
     * Render a horizontal Braille bar of $cells width representing
     * $ratio in [0..1]. Each cell carries 2 horizontal sub-pixels so
     * the smallest visible step is 1/(2*$cells) of the bar.
     */
    private static function brailleHBar(float $ratio, int $cells): string
    {
        if ($ratio < 0) {
            $ratio = 0;
        }
        if ($ratio > 1) {
            $ratio = 1;
        }
        $sub = (int)round($ratio * $cells * 2);
        $out = '';
        for ($c = 0; $c < $cells; $c++) {
            $left_p = $c * 2 < $sub;
            $right_p = $c * 2 + 1 < $sub;
            if ($left_p && $right_p) {
                $out .= '⣿';
            } elseif ($left_p) {
                $out .= '⡇';
            } else {
                $out .= ' ';
            }
        }
        return $out;
    }

    // ---------- aggregation caches ----------

    /**
     * @return array{matched_samples:int, rows:list<array{int,int,string}>}
     */
    private function ensureList(): array
    {
        if ($this->list_cache !== null) {
            return $this->list_cache;
        }
        $state = $this->currentState();
        $view = $state->mode === ExploreMode::ListTotal
            ? Aggregator::totalTime($this->model, $this->opts)
            : Aggregator::selfTime($this->model, $this->opts);
        return $this->list_cache = $this->buildCache($view, $this->opts->no_line);
    }

    /**
     * @return array{matched_samples:int, rows:list<array{int,int,string}>}
     */
    private function ensureCallers(): array
    {
        if ($this->callers_cache !== null) {
            return $this->callers_cache;
        }
        $state = $this->currentState();
        if ($state->focus_id === null) {
            return $this->callers_cache = ['matched_samples' => 0, 'rows' => []];
        }
        $view = Aggregator::callersOf($this->model, $state->focus_id, $this->opts);
        return $this->callers_cache = $this->buildCache($view, $this->opts->no_line);
    }

    /**
     * @return array{matched_samples:int, rows:list<array{int,int,string}>}
     */
    private function ensureCallees(): array
    {
        if ($this->callees_cache !== null) {
            return $this->callees_cache;
        }
        $state = $this->currentState();
        if ($state->focus_id === null) {
            return $this->callees_cache = ['matched_samples' => 0, 'rows' => []];
        }
        $view = Aggregator::calleesOf($this->model, $state->focus_id, $this->opts);
        return $this->callees_cache = $this->buildCache($view, $this->opts->no_line);
    }

    /**
     * Self-time or total-time top — the data behind the overview sidebar.
     *
     * Follows the user's global `no-line` setting (toggled with `n`) so
     * the sidebar always shows results in the same id space the rest of
     * the explorer is reading. Sort flavour is controlled by
     * {@see self::$overview_sort}.
     *
     * @return array{matched_samples:int, rows:list<array{int,int,string}>}
     */
    private function ensureOverview(): array
    {
        if ($this->overview_cache !== null) {
            return $this->overview_cache;
        }
        $view = $this->overview_sort === 'total'
            ? Aggregator::totalTime($this->model, $this->opts)
            : Aggregator::selfTime($this->model, $this->opts);
        return $this->overview_cache = $this->buildCache($view, $this->opts->no_line);
    }

    /**
     * @param array{counts: array<int, int>, matched_samples: int} $view
     * @return array{matched_samples:int, rows:list<array{int,int,string}>}
     */
    private function buildCache(array $view, bool $no_line): array
    {
        $counts = $view['counts'];
        arsort($counts);
        $rows = [];
        foreach ($counts as $key_id => $count) {
            $label = Aggregator::labelFor($this->model, $key_id, $no_line);
            $rows[] = [$count, $key_id, $label];
        }
        return [
            'matched_samples' => $view['matched_samples'],
            'rows' => $rows,
        ];
    }

    /**
     * Drop the per-pane aggregator caches.
     *
     * The overview cache is preserved by default because it's invariant
     * across focus changes (it depends only on the model + global match
     * + the overview sort, not on the current sandwich focus or no-line
     * setting). Pass `include_overview: true` for events that *do*
     * change overview content (match filter, overview sort flip).
     */
    private function invalidate(bool $include_overview = false): void
    {
        $this->list_cache = null;
        $this->callers_cache = null;
        $this->callees_cache = null;
        // The sandwich-flame popup cache is keyed by focus / no-line /
        // match-re, all of which are checked at access time, so a stale
        // entry will simply be discarded on the next ensureSandwichTree()
        // call. Nothing to bust here.
        if ($include_overview) {
            $this->overview_cache = null;
        }
    }

    /**
     * Build (or return cached) sandwich flame tree for the current focus.
     *
     * @return array{
     *   focus_count:int,
     *   callers:array<int,array{count:int,children:array}>,
     *   callees:array<int,array{count:int,children:array}>,
     * }|null
     */
    private function ensureSandwichTree(): ?array
    {
        $state = $this->currentState();
        if ($state->focus_id === null) {
            return null;
        }
        if (
            $this->sandwich_cache !== null
            && $this->sandwich_cache_focus_id === $state->focus_id
            && $this->sandwich_cache_no_line === $this->opts->no_line
            && $this->sandwich_cache_match_re === $this->opts->match_re
        ) {
            return $this->sandwich_cache;
        }
        $this->sandwich_cache = SandwichBuilder::build(
            $this->model,
            $state->focus_id,
            $this->opts,
        );
        $this->sandwich_cache_focus_id = $state->focus_id;
        $this->sandwich_cache_no_line = $this->opts->no_line;
        $this->sandwich_cache_match_re = $this->opts->match_re;
        return $this->sandwich_cache;
    }

    /**
     * @param list<array{int,int,string}> $rows
     * @return list<array{int,int,string}>
     */
    private function applyViewFilter(array $rows, ?string $filter): array
    {
        if ($filter === null) {
            return $rows;
        }
        $re = '#' . str_replace('#', '\\#', $filter) . '#';
        return array_values(array_filter(
            $rows,
            static fn(array $row): bool => preg_match($re, $row[2]) === 1,
        ));
    }

    private function clampSelection(int &$selected, int &$top_row, int $total, int $visible): void
    {
        if ($total === 0 || $visible <= 0) {
            $selected = 0;
            $top_row = 0;
            return;
        }
        if ($selected < 0) {
            $selected = 0;
        }
        if ($selected >= $total) {
            $selected = $total - 1;
        }
        if ($selected < $top_row) {
            $top_row = $selected;
        }
        if ($selected >= $top_row + $visible) {
            $top_row = $selected - $visible + 1;
        }
        if ($top_row < 0) {
            $top_row = 0;
        }
    }

    // ---------- event handling ----------

    private function dispatch(string $key): void
    {
        if ($this->help_open) {
            $this->help_open = false;
            return;
        }
        if ($this->sandwich_popup_open) {
            $this->sandwich_popup_open = false;
            return;
        }
        if ($this->tree_popup_direction !== null) {
            $this->tree_popup_direction = null;
            return;
        }
        if ($this->prompt_label !== null) {
            $this->dispatchPrompt($key);
            return;
        }

        $action = $this->keymap->resolve($key);
        if ($action === null) {
            return;
        }

        $this->status = '';
        $state = $this->currentState();

        switch ($action) {
            case Keymap::ACTION_QUIT:
                $this->running = false;
                return;

            case Keymap::ACTION_HELP:
                $this->help_open = true;
                return;

            case Keymap::ACTION_UP:
                $this->moveSelection(-1);
                return;

            case Keymap::ACTION_DOWN:
                $this->moveSelection(1);
                return;

            case Keymap::ACTION_PAGE_UP:
                $this->moveSelection(-10);
                return;

            case Keymap::ACTION_PAGE_DOWN:
                $this->moveSelection(10);
                return;

            case Keymap::ACTION_HOME:
                $this->setSelection(0);
                return;

            case Keymap::ACTION_END:
                $this->setSelection(PHP_INT_MAX);
                return;

            case Keymap::ACTION_FOCUS_ENTER:
                $this->focusSelected();
                return;

            case Keymap::ACTION_TOGGLE_PANE:
                if ($state->mode === ExploreMode::Sandwich) {
                    $this->stack[count($this->stack) - 1]
                        = $state->withActivePane($state->active_pane->next());
                }
                return;

            case Keymap::ACTION_TOGGLE_PANE_REVERSE:
                if ($state->mode === ExploreMode::Sandwich) {
                    $this->stack[count($this->stack) - 1]
                        = $state->withActivePane($state->active_pane->prev());
                }
                return;

            case Keymap::ACTION_FOLLOW_OVERVIEW:
                $this->overview_follow = !$this->overview_follow;
                $this->status = 'overview follow: ' . ($this->overview_follow ? 'on' : 'off');
                return;

            case Keymap::ACTION_TOGGLE_MINI_FLAME:
                $this->mini_flame_enabled = !$this->mini_flame_enabled;
                $this->status = 'mini-flame strip: ' . ($this->mini_flame_enabled ? 'on' : 'off');
                return;

            case Keymap::ACTION_OPEN_SANDWICH_FLAME:
                if ($state->mode !== ExploreMode::Sandwich || $state->focus_id === null) {
                    $this->status = 'sandwich flame: focus a frame first';
                    return;
                }
                $this->sandwich_popup_open = true;
                return;

            case Keymap::ACTION_OPEN_CALLEE_TREE:
                if ($state->mode !== ExploreMode::Sandwich || $state->focus_id === null) {
                    $this->status = 'callee tree: focus a frame first';
                    return;
                }
                $this->tree_popup_direction = 'callees';
                return;

            case Keymap::ACTION_OPEN_CALLER_TREE:
                if ($state->mode !== ExploreMode::Sandwich || $state->focus_id === null) {
                    $this->status = 'caller tree: focus a frame first';
                    return;
                }
                $this->tree_popup_direction = 'callers';
                return;

            case Keymap::ACTION_CALLERS:
                if ($state->mode === ExploreMode::Sandwich) {
                    $this->stack[count($this->stack) - 1]
                        = $state->withActivePane(ActivePane::Callers);
                }
                return;

            case Keymap::ACTION_CALLEES:
                if ($state->mode === ExploreMode::Sandwich) {
                    $this->stack[count($this->stack) - 1]
                        = $state->withActivePane(ActivePane::Callees);
                }
                return;

            case Keymap::ACTION_BACK:
                $this->popFocus();
                return;

            case Keymap::ACTION_VIEW_SELF:
                if ($state->mode === ExploreMode::Sandwich) {
                    $this->setOverviewSort('self');
                    $this->stack[count($this->stack) - 1]
                        = $state->withActivePane(ActivePane::Overview);
                    return;
                }
                $this->resetTo(ExploreMode::ListSelf);
                return;

            case Keymap::ACTION_VIEW_TOTAL:
                if ($state->mode === ExploreMode::Sandwich) {
                    $this->setOverviewSort('total');
                    $this->stack[count($this->stack) - 1]
                        = $state->withActivePane(ActivePane::Overview);
                    return;
                }
                $this->resetTo(ExploreMode::ListTotal);
                return;

            case Keymap::ACTION_VIEW_OVERVIEW:
                // Fullscreen "overview as a list view": ListSelf with
                // no-line forced on. Same content as the sidebar but
                // gets the whole window so labels aren't truncated and
                // there's room to navigate the long tail.
                if (!$this->opts->no_line) {
                    $this->opts = $this->opts->withNoLine(true);
                }
                $this->resetTo(ExploreMode::ListSelf);
                $this->status = 'overview (no-line self-time top)';
                return;

            case Keymap::ACTION_FILTER_VIEW:
                $this->openPrompt(
                    'view filter (PCRE, empty = clear): ',
                    function (string $value): void {
                        $state = $this->currentState();
                        $value = $value === '' ? null : $value;
                        if ($value !== null && @preg_match('#' . str_replace('#', '\\#', $value) . '#', '') === false) {
                            $this->status = "invalid view filter: {$value}";
                            return;
                        }
                        $this->stack[count($this->stack) - 1] = $state->withViewFilter($value);
                        $this->resetScrolls();
                        $this->status = $value === null ? 'view filter cleared' : "view filter: {$value}";
                    },
                );
                return;

            case Keymap::ACTION_FILTER_MATCH:
                $this->openPrompt(
                    'global match (PCRE, empty = clear): ',
                    function (string $value): void {
                        if ($value === '') {
                            $this->opts = $this->opts->withMatch(null);
                            $this->status = 'global match cleared';
                        } else {
                            $re = '#' . str_replace('#', '\\#', $value) . '#';
                            if (@preg_match($re, '') === false) {
                                $this->status = "invalid match: {$value}";
                                return;
                            }
                            $this->opts = $this->opts->withMatch($re);
                            $this->status = "global match: {$value}";
                        }
                        // The match filter changes overview content too,
                        // so reset its scroll and bust its cache.
                        $this->resetScrolls(include_overview: true);
                        $this->invalidate(include_overview: true);
                    },
                );
                return;

            case Keymap::ACTION_NO_LINE:
                $this->opts = $this->opts->withNoLine(!$this->opts->no_line);
                $this->refocusForNoLineToggle();
                // The overview now follows the global no-line setting,
                // so its content (and therefore its row indices) shifts
                // with this toggle — bust the cache and reset its
                // cursor along with everything else.
                $this->invalidate(include_overview: true);
                $this->resetScrolls(include_overview: true);
                $this->status = 'no-line: ' . ($this->opts->no_line ? 'on' : 'off');
                return;

            case Keymap::ACTION_TOGGLE_OVERVIEW:
                // Promote whatever the auto-rule currently says to the
                // explicit override, then flip it. After this any further
                // toggles cycle the explicit value rather than re-engaging
                // auto.
                [$cols, ] = $this->term->size();
                $current = $this->sidebar_override ?? ($cols >= 120);
                $this->sidebar_override = !$current;
                $this->status = 'overview: ' . ($this->sidebar_override ? 'on' : 'off');
                return;
        }
    }

    private function dispatchPrompt(string $key): void
    {
        if ($key === "\r" || $key === "\n") {
            $value = $this->prompt_buffer;
            $commit = $this->prompt_commit;
            $this->closePrompt();
            if ($commit !== null) {
                $commit($value);
            }
            return;
        }
        if ($key === "\e" || $key === "\x03") {
            $this->closePrompt();
            $this->status = 'cancelled';
            return;
        }
        if ($key === "\x7f" || $key === "\b") {
            if ($this->prompt_buffer !== '') {
                $this->prompt_buffer = mb_substr($this->prompt_buffer, 0, -1);
            }
            return;
        }
        // Filter out control sequences (e.g. arrow keys) inside the prompt.
        if (str_starts_with($key, "\e")) {
            return;
        }
        $this->prompt_buffer .= $key;
    }

    private function openPrompt(string $label, callable $commit): void
    {
        $this->prompt_label = $label;
        $this->prompt_buffer = '';
        $this->prompt_commit = $commit;
    }

    private function closePrompt(): void
    {
        $this->prompt_label = null;
        $this->prompt_buffer = '';
        $this->prompt_commit = null;
    }

    private function moveSelection(int $delta): void
    {
        $state = $this->currentState();
        if ($state->mode !== ExploreMode::Sandwich) {
            $this->list_selected += $delta;
            return;
        }
        match ($state->active_pane) {
            ActivePane::Callers  => $this->callers_selected += $delta,
            ActivePane::Callees  => $this->callees_selected += $delta,
            ActivePane::Overview => $this->overview_selected += $delta,
        };
        if ($state->active_pane === ActivePane::Overview && $this->overview_follow) {
            $this->liveFollowOverviewFocus();
        }
    }

    private function setSelection(int $value): void
    {
        $state = $this->currentState();
        if ($state->mode !== ExploreMode::Sandwich) {
            $this->list_selected = $value === PHP_INT_MAX
                ? max(0, count($this->ensureList()['rows']) - 1)
                : $value;
            $this->list_top_row = 0;
            return;
        }
        switch ($state->active_pane) {
            case ActivePane::Callers:
                $this->callers_selected = $value === PHP_INT_MAX
                    ? max(0, count($this->ensureCallers()['rows']) - 1)
                    : $value;
                $this->callers_top_row = 0;
                return;
            case ActivePane::Callees:
                $this->callees_selected = $value === PHP_INT_MAX
                    ? max(0, count($this->ensureCallees()['rows']) - 1)
                    : $value;
                $this->callees_top_row = 0;
                return;
            case ActivePane::Overview:
                $this->overview_selected = $value === PHP_INT_MAX
                    ? max(0, count($this->ensureOverview()['rows']) - 1)
                    : $value;
                $this->overview_top_row = 0;
                if ($this->overview_follow) {
                    $this->liveFollowOverviewFocus();
                }
                return;
        }
    }

    /**
     * Replace the current sandwich state's focus with whatever the
     * overview cursor is currently pointing at — without pushing a new
     * state on the history stack.
     *
     * Used by the `f` "follow" mode so navigating the overview previews
     * each row inline. The history stack stays untouched, so `Enter`
     * still acts as a "checkpoint" the user can return to via `u`.
     */
    private function liveFollowOverviewFocus(): void
    {
        $state = $this->currentState();
        if ($state->mode !== ExploreMode::Sandwich) {
            return;
        }
        // Clamp the cursor first so we don't try to read past the end.
        $rows = $this->ensureOverview()['rows'];
        if ($rows === []) {
            return;
        }
        if ($this->overview_selected < 0) {
            $this->overview_selected = 0;
        }
        if ($this->overview_selected >= count($rows)) {
            $this->overview_selected = count($rows) - 1;
        }
        [, $key_id, $label] = $rows[$this->overview_selected];
        if ($key_id < 0) {
            return; // synthetic <root>/<leaf>, skip
        }
        if ($state->focus_id === $key_id) {
            return; // no-op
        }
        // Replace the top state in place — this is the key bit that
        // makes follow mode not pollute the history stack.
        $this->stack[count($this->stack) - 1] = $state->withFocus($key_id, $label);
        // Callers/callees content is now stale; reset their scrolls
        // and bust their caches. Overview cursor stays put because the
        // user just moved it.
        $this->callers_cache = null;
        $this->callees_cache = null;
        $this->callers_selected = 0;
        $this->callers_top_row = 0;
        $this->callees_selected = 0;
        $this->callees_top_row = 0;
    }

    /**
     * Promote the active pane's selected row to a new focus and push a
     * new sandwich state on the stack. The same pane stays active so
     * the user can keep climbing in one direction.
     *
     * Snapshots the overview cursor into the *outgoing* state before
     * pushing so `popFocus` can restore it later — the user shouldn't
     * have to re-find their place in the overview after drilling down.
     */
    private function focusSelected(): void
    {
        $state = $this->currentState();

        if ($state->mode !== ExploreMode::Sandwich) {
            $rows = $this->applyViewFilter($this->ensureList()['rows'], $state->view_filter);
            if (!isset($rows[$this->list_selected])) {
                return;
            }
            [, $key_id, $label] = $rows[$this->list_selected];
            if ($key_id < 0) {
                $this->status = 'cannot focus synthetic <root>/<leaf>';
                return;
            }
            $this->stack[] = new ViewState(
                mode: ExploreMode::Sandwich,
                focus_id: $key_id,
                focus_label: $label,
                active_pane: ActivePane::Callers,
            );
            $this->resetScrolls();
            $this->invalidate();
            return;
        }

        // Sandwich → Sandwich: promote the active pane's selected row.
        $rows = [];
        $idx = 0;
        switch ($state->active_pane) {
            case ActivePane::Callers:
                $rows = $this->applyViewFilter($this->ensureCallers()['rows'], $state->view_filter);
                $idx = $this->callers_selected;
                break;
            case ActivePane::Callees:
                $rows = $this->applyViewFilter($this->ensureCallees()['rows'], $state->view_filter);
                $idx = $this->callees_selected;
                break;
            case ActivePane::Overview:
                $rows = $this->ensureOverview()['rows'];
                $idx = $this->overview_selected;
                break;
        }
        if (!isset($rows[$idx])) {
            return;
        }
        [, $key_id, $label] = $rows[$idx];
        if ($key_id < 0) {
            $this->status = 'cannot focus synthetic <root>/<leaf>';
            return;
        }
        // Snapshot the current overview cursor into the *outgoing* state
        // before pushing the new one. popFocus will restore it on the
        // way back so the user doesn't lose their place in the sidebar.
        $this->stack[count($this->stack) - 1] = $state->withOverviewCursor(
            $this->overview_selected,
            $this->overview_top_row,
        );
        $this->stack[] = new ViewState(
            mode: ExploreMode::Sandwich,
            focus_id: $key_id,
            focus_label: $label,
            active_pane: $state->active_pane,
            overview_selected: $this->overview_selected,
            overview_top_row: $this->overview_top_row,
        );
        $this->resetScrolls();
        $this->invalidate();
    }

    private function popFocus(): void
    {
        if (count($this->stack) <= 1) {
            $this->status = 'no history';
            return;
        }
        array_pop($this->stack);
        $this->resetScrolls();
        $this->invalidate();
        // Restore the overview cursor from whatever the now-top state
        // saved when it was pushed (or 0 for fresh states), so `u`
        // returns the user to where they were *in the overview* too,
        // not just to the previous focus.
        $restored = $this->currentState();
        $this->overview_selected = $restored->overview_selected;
        $this->overview_top_row = $restored->overview_top_row;
    }

    private function resetTo(ExploreMode $mode): void
    {
        $this->stack = [new ViewState(mode: $mode)];
        $this->resetScrolls();
        $this->invalidate();
    }

    /**
     * Reset cursor + scroll position for the list / callers / callees
     * panes. The overview pane is preserved by default — focus changes
     * shouldn't lose the user's place in the big-picture map. Pass
     * `include_overview: true` only for events that genuinely make the
     * overview's row indices stale (sort flip, match filter, etc).
     */
    private function resetScrolls(bool $include_overview = false): void
    {
        $this->list_selected = 0;
        $this->list_top_row = 0;
        $this->callers_selected = 0;
        $this->callers_top_row = 0;
        $this->callees_selected = 0;
        $this->callees_top_row = 0;
        if ($include_overview) {
            $this->overview_selected = 0;
            $this->overview_top_row = 0;
        }
    }

    /**
     * After toggling no-line, the current focus_id is in the wrong ID
     * space. Best-effort: re-resolve focus_label to the new space.
     */
    private function refocusForNoLineToggle(): void
    {
        $state = $this->currentState();
        if ($state->focus_id === null || $state->focus_label === null) {
            return;
        }
        if ($this->opts->no_line) {
            $new_id = $this->model->no_line_map[$state->focus_id] ?? null;
            if ($new_id === null) {
                return;
            }
            $new_label = $this->model->frame_keys_no_line[$new_id] ?? $state->focus_label;
        } else {
            // no-line → line: pick the most-frequent line frame for this name.
            $best_id = null;
            $best_count = -1;
            $counts = [];
            foreach ($this->model->samples as $stack) {
                foreach ($stack as $fid) {
                    if (($this->model->no_line_map[$fid] ?? -1) === $state->focus_id) {
                        $counts[$fid] = ($counts[$fid] ?? 0) + 1;
                    }
                }
            }
            foreach ($counts as $fid => $c) {
                if ($c > $best_count) {
                    $best_count = $c;
                    $best_id = $fid;
                }
            }
            if ($best_id === null) {
                return;
            }
            $new_id = $best_id;
            $new_label = $this->model->frame_keys[$best_id] ?? $state->focus_label;
        }
        $this->stack[count($this->stack) - 1] = new ViewState(
            mode: $state->mode,
            focus_id: $new_id,
            focus_label: $new_label,
            view_filter: $state->view_filter,
            active_pane: $state->active_pane,
        );
    }

    private function currentState(): ViewState
    {
        return $this->stack[count($this->stack) - 1];
    }

    /**
     * Pick a 1-character vertical block representing $ratio in [0..1].
     * Used as a sparkline-style data bar at the start of each row to
     * give an at-a-glance feel for relative sizes within a pane.
     */
    private static function barChar(float $ratio): string
    {
        if ($ratio <= 0) {
            return ' ';
        }
        if ($ratio < 0.125) {
            return '▁';
        }
        if ($ratio < 0.250) {
            return '▂';
        }
        if ($ratio < 0.375) {
            return '▃';
        }
        if ($ratio < 0.500) {
            return '▄';
        }
        if ($ratio < 0.625) {
            return '▅';
        }
        if ($ratio < 0.750) {
            return '▆';
        }
        if ($ratio < 0.875) {
            return '▇';
        }
        return '█';
    }

    /**
     * Greatest count among a list of cached pane rows. Used to scale
     * the per-row bar so the visually-largest row is always full block.
     *
     * @param list<array{int, int, string}> $rows
     */
    private static function maxRowCount(array $rows): int
    {
        $max = 0;
        foreach ($rows as [$count, , ]) {
            if ($count > $max) {
                $max = $count;
            }
        }
        return $max;
    }

    private static function shorten(string $s, int $width): string
    {
        if ($width <= 0) {
            return '';
        }
        if (mb_strlen($s) <= $width) {
            return $s;
        }
        if ($width <= 1) {
            return mb_substr($s, 0, $width);
        }
        return mb_substr($s, 0, $width - 1) . '…';
    }

    /**
     * Truncate to width and right-pad with spaces so the line occupies
     * exactly $width display columns. Used everywhere a column-aligned
     * region (sidebar / sandwich pane) needs predictable widths.
     */
    private static function padOrShorten(string $s, int $width): string
    {
        $shortened = self::shorten($s, $width);
        $len = mb_strlen($shortened);
        if ($len < $width) {
            $shortened .= str_repeat(' ', $width - $len);
        }
        return $shortened;
    }
}
