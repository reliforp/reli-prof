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
    private TerminalInterface $term;
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
     * Sandwich tree cache (built once per focus / no-line / match-re).
     *
     * Both the flame body and the indented-tree body read from this:
     * a depth-first walk of every sample stack containing the focus
     * frame, materialised as a recursive
     *   array<int, array{count:int, children:array}>
     * for each direction. {@see SandwichBuilder} does the actual
     * build; everything here is just memoization.
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

    /**
     * Cursor state for the SandwichView::Flame body. The cursor is a
     * single highlighted bar identified by its visual row in the
     * sandwich body area and a column position; navigation moves it
     * row-by-row (auto-snapping x to whichever bar contains the
     * cursor's column at the new row) or sibling-by-sibling within a
     * row. Sentinel value of -1 means "lazy-init to focus row centre
     * on the next render", used when switching into the flame view
     * before we know layout dimensions.
     */
    private int $flame_cursor_visual_row = -1;
    private int $flame_cursor_x = -1;

    /**
     * Whether sandwich-flame body labels are aligned to the right
     * edge of each bar instead of the default left edge. Toggled with
     * `A` while in any sandwich view (the toggle is only meaningful
     * for the flame body, but it's harmless elsewhere). Right-align
     * makes the label sit next to its bar's "ownership boundary"
     * which can help separate siblings visually when narrow bars
     * pile up.
     */
    private bool $flame_label_right_align = false;

    /**
     * Cursor + scroll state for the SandwichView::TreeCallees /
     * TreeCallers bodies. The body builds the full sandwich tree into
     * a flat indented line list (capped at TREE_BODY_MAX_LINES) and
     * tracks the cursor + top-of-window independently so cursor moves
     * auto-scroll the visible window. Reset on focus or view change.
     */
    private int $tree_top_row = 0;
    private int $tree_cursor_row = 0;

    /**
     * Set of folded subtree paths inside the tree body. Each path is
     * the cursor row's chain of key_ids from depth-1 to the row's
     * depth, joined with '/'. The walker skips recursing into a node
     * whose path is in this set, so its children disappear from the
     * flat line list. Reset whenever the focus / view changes (the
     * fold state is meaningful only for one specific tree direction
     * of one specific focus, so it'd be misleading to carry it over).
     *
     * Keys are intended as path strings (slash-joined int key_ids),
     * but PHP normalises a purely-numeric string array key to int at
     * insert time, so the runtime key type is `array-key`. The lookup
     * site must handle both, hence the explicit string-cast in the
     * recursive-unfold loop below.
     *
     * @var array<array-key, true>
     */
    private array $tree_folded = [];

    /**
     * Double-click detection state. Tracks the last left-click's
     * timestamp and position so a second click within the threshold
     * at the same cell can be promoted to a focus action.
     */
    private float $last_click_time = 0.0;
    private int $last_click_row = -1;
    private int $last_click_col = -1;
    private const DOUBLE_CLICK_MS = 400;

    /**
     * Cached mini-flame pixel → overview key_id mapping, populated
     * during render so click handlers can look up which frame a
     * column corresponds to without recomputing the allocation.
     *
     * @var list<int>  pixel_index → key_id (-1 = unallocated)
     */
    private array $mini_flame_pixel_keys = [];

    /**
     * Mouse scroll delta: how many rows each scroll event moves.
     * Adjustable via the [δN] indicator in the header (click to
     * cycle presets, scroll to fine-tune).
     */
    private int $scroll_delta = 1;

    /** @var list<int> preset values cycled through on click */
    private const SCROLL_DELTA_PRESETS = [1, 2, 3, 5, 10];

    /**
     * Column range of the [δN] indicator on header line 2 (0-based,
     * exclusive end). Set by renderHeader() so the mouse dispatcher
     * knows where the widget is.
     *
     * @var array{int, int}
     */
    private array $scroll_delta_cols = [0, 0];

    public function __construct(TraceModel $model, TerminalInterface $term, Keymap $keymap)
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
        // The MIN_ROWS check above guarantees $rows is large enough
        // that body_rows here is always >= 3, so no extra clamp.
        $body_rows = $rows - 6 - ($show_mini_flame ? 1 : 0);

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
            $body_lines = match ($state->sandwich_view) {
                SandwichView::Panes => $this->renderSandwichPanesLines(
                    $state,
                    $body_width,
                    $body_rows,
                ),
                SandwichView::Flame => $this->renderSandwichFlameBodyLines(
                    $state,
                    $body_width,
                    $body_rows,
                ),
                SandwichView::TreeCallees => $this->renderSandwichTreeBodyLines(
                    $state,
                    'callees',
                    $body_width,
                    $body_rows,
                ),
                SandwichView::TreeCallers => $this->renderSandwichTreeBodyLines(
                    $state,
                    'callers',
                    $body_width,
                    $body_rows,
                ),
            };
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
            $this->mini_flame_pixel_keys = [];
            return str_repeat(' ', max(0, $width));
        }

        $total = 0;
        foreach ($rows as [$count, , ]) {
            $total += $count;
        }
        if ($total === 0) {
            $this->mini_flame_pixel_keys = [];
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
        $total_f = (float)$total;
        $total_pixels_f = (float)$total_pixels;
        foreach ($rows as $i => [$count, , ]) {
            $w = (int)round((float)$count / $total_f * $total_pixels_f);
            if ($w === 0 && $count > 0) {
                $w = 1;
            }
            $virtual_widths[$i] = $w;
            $virtual_total += $w;
        }

        // Walk frames in order, allocating subpixels proportionally.
        // The break at $total_pixels truncates the long tail.
        // Also builds a parallel pixel → key_id mapping for click handling.
        $pos = 0;
        $cursor_rendered_in_strip = false;
        /** @var list<int> pixel_index → key_id (-1 = unallocated) */
        $pixel_keys = array_fill(0, $total_pixels, -1);
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
                $pixel_keys[$p] = $key_id;
            }
            if ($is_highlight) {
                $cursor_rendered_in_strip = true;
            }
            $pos = $end;
        }
        $this->mini_flame_pixel_keys = $pixel_keys;

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
            $marker_pixel = (int)floor((float)$virtual_start / (float)$virtual_total * (float)$total_pixels);
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
        $width = (int)((float)$cols * 0.28);
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
            ExploreMode::Sandwich  => match ($state->sandwich_view) {
                SandwichView::Panes       => 'sandwich panes (caller/callee drilldown)',
                SandwichView::Flame       => 'sandwich flame (bidirectional flame chart)',
                SandwichView::TreeCallees => 'callee tree (toward leaves)',
                SandwichView::TreeCallers => 'caller tree (toward roots)',
            },
        };
        $line2 = sprintf('mode: %s   history: %d back', $mode_label, $hist);
        $line3_left = sprintf(
            'no-line: %s   match: %s   filter: %s',
            $this->opts->no_line ? 'on' : 'off',
            $this->opts->match_re ?? '(none)',
            $state->view_filter ?? '(none)',
        );
        $delta_label = sprintf('[δ%d]', $this->scroll_delta);
        $delta_len = mb_strlen($delta_label);
        $left_len = mb_strlen($line3_left);
        // Place the indicator right after the settings text so it
        // stays near the left-side overview sidebar on wide terminals.
        if ($left_len + 3 + $delta_len <= $cols) {
            $delta_col_start = $left_len + 3;
            $line3 = $line3_left . '   ' . $delta_label;
        } else {
            // Terminal too narrow — omit the indicator.
            $delta_col_start = 0;
            $line3 = $line3_left;
        }
        $this->scroll_delta_cols = [$delta_col_start, $delta_col_start + $delta_len];

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
        $denom = (float)max(1, $cache['matched_samples']);
        $max_count = (float)max(1, self::maxRowCount($rows_data));

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
            $pct = (float)$count / $denom * 100.0;
            $marker = $idx === $this->list_selected ? '>' : ' ';
            $bar = self::barChar((float)$count / $max_count);
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
    private function renderSandwichPanesLines(ViewState $state, int $width, int $body_rows): array
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
        $banner_lines = $this->renderFocusBannerLines(
            $state,
            $width,
            $state->active_pane === ActivePane::Focus,
        );
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

        $denom_f = (float)max(1, $denom);
        $max_count = (float)max(1, self::maxRowCount($rows));
        for ($i = 0; $i < $visible; $i++) {
            $idx = $top_row + $i;
            if (!isset($rows[$idx])) {
                $out[] = str_repeat(' ', $width);
                continue;
            }
            [$count, , $label] = $rows[$idx];
            $pct = (float)$count / $denom_f * 100.0;
            $is_sel = $active && $idx === $selected;
            $sel_mark = $is_sel ? '>' : ' ';
            $bar = self::barChar((float)$count / $max_count);
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
    private function renderFocusBannerLines(ViewState $state, int $width, bool $active = false): array
    {
        $label = $state->focus_label ?? '<none>';
        $stats = $this->lookupFocusSelfStats($state);
        $stats_text = $stats !== null
            ? sprintf(' — %s (%5.1f%%)', number_format($stats['count']), $stats['pct'])
            : '';

        $top = '┌' . str_repeat('─', max(0, $width - 2)) . '┐';
        $bottom = '└' . str_repeat('─', max(0, $width - 2)) . '┘';

        // The cursor marker (▶ when active) takes the spot the
        // border's left vertical bar normally occupies, so the
        // banner stays exactly $width cells wide regardless of state.
        $left_marker = $active ? '│▶' : '│ ';
        $left = $left_marker . ' focus: ';
        $right = $stats_text . ' │';
        $label_room = max(0, $width - mb_strlen($left) - mb_strlen($right));
        $label_short = self::shorten($label, $label_room);
        $inner = $left . $label_short;
        $padding = max(0, $width - mb_strlen($inner) - mb_strlen($right));
        $inner .= str_repeat(' ', $padding) . $right;
        $inner = self::padOrShorten($inner, $width);

        // When active, render the banner in reverse video so it's
        // visually obvious the cursor is parked here. The plain
        // bold style of the inactive banner stays for the non-active
        // case (matching what panes mode looked like before).
        $style = $active ? "\e[1;7m" : "\e[1m";
        $end   = $active ? "\e[27;22m" : "\e[22m";

        return [
            $style . self::padOrShorten($top, $width) . $end,
            $style . $inner . $end,
            $style . self::padOrShorten($bottom, $width) . $end,
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
                $denom = (float)max(1, $cache['matched_samples']);
                return ['count' => $count, 'pct' => (float)$count / $denom * 100.0];
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
        $denom = (float)max(1, $cache['matched_samples']);
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

        $max_count = (float)max(1, self::maxRowCount($rows));
        for ($i = 0; $i < $visible; $i++) {
            $idx = $top_row + $i;
            if (!isset($rows[$idx])) {
                $out[] = str_repeat(' ', $width);
                continue;
            }
            [$count, $key_id, $label] = $rows[$idx];
            $pct = (float)$count / $denom * 100.0;
            $is_focus = $focus_idx !== null && $idx === $focus_idx;
            $is_sel = $is_active && $idx === $this->overview_selected;
            // Selection cursor takes precedence over the focus marker
            // since "what I'm pointing at" matters more during navigation.
            $marker = $is_sel
                ? '>'
                : ($is_focus ? '◆' : ' ');
            $bar = self::barChar((float)$count / $max_count);
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
        // Delegate to the view-aware helper introduced by the unified
        // sandwich-mode refactor. It already knows how to read the
        // cursor out of every SandwichView (panes / flame / tree),
        // and returns null for synthetic <root>/<leaf> rows so the
        // mini-flame highlight only ever lands on real frames.
        $cursor = $this->getActiveCursorFrame();
        return $cursor === null ? null : $cursor['key_id'];
    }

    private function renderFooter(ViewState $state, int $cols): string
    {
        if ($state->mode === ExploreMode::Sandwich) {
            $follow = $this->overview_follow ? '[f*]' : '[f]';
            $flame = $this->mini_flame_enabled ? '[F*]' : '[F]';

            // Per-view operation hints. Each view binds the navigation
            // keys to a different action so the footer should explain
            // what they currently do — generic "[↑↓] move" was true
            // but unhelpful when the same key meant a different thing
            // depending on which sandwich_view was active.
            $view_keys = match ($state->sandwich_view) {
                SandwichView::Panes
                    => '[Tab] pane  [↑↓] sel  [←/→] caller/callee  [Enter] focus',
                SandwichView::Flame
                    => '[↑↓] depth  [←/→] siblings  [Enter] focus',
                SandwichView::TreeCallees,
                SandwichView::TreeCallers
                    => '[↑↓] scroll  [←/→] fold  [H/L] fold-rec  [Enter] focus',
            };

            $footer = $view_keys
                . '  [P/S/>/<] view  [u] back  [s/t/O] self/total/overview  '
                . $follow . ' follow  ' . $flame . ' flame  '
                . '[/] filter  [m] match  [n] no-line  [o] sidebar  '
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
            '  Tab          cycle active pane: callers → focus → callees → overview',
            '               (focus pane is panes-view only — Tab skips it elsewhere)',
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
            '  P            sandwich panes view (caller / callee 1-step drilldown)',
            '  S            sandwich flame view (↑↓ depth, ←→ siblings, Enter focus)',
            '  >            callee tree view (scroll + Enter focuses)',
            '  <            caller tree view (scroll + Enter focuses)',
            '               (mode keys carry the cursor frame across as the new focus)',
            '  ← / →        tree: fold / unfold cursor subtree',
            '  H / L        tree: fold / unfold cursor subtree recursively',
            '  A            flame: toggle bar label alignment (left ⇄ right)',
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
     * Inline flame-chart body for SandwichView::Flame.
     *
     * Layout: caller tree growing upward from the focus row, the focus
     * bar in the middle, callee tree growing downward, with a 1-line
     * cursor-info footer at the bottom. Bar widths are proportional to
     * sample counts of the focus, so a child taking half its parent's
     * bar means half of the samples that flowed through the parent
     * then went into that child.
     *
     * The cursor (a single highlighted bar) overlays the regular
     * alternating dim/reverse style — see buildSandwichLayout for the
     * visual_row index used by both this renderer and the cursor
     * navigation handlers.
     *
     * @return list<string> exactly $body_rows lines, padded as needed
     */
    private function renderSandwichFlameBodyLines(
        ViewState $state,
        int $width,
        int $body_rows,
    ): array {
        if ($state->focus_id === null) {
            return $this->padBodyLines([], $width, $body_rows);
        }
        $layout = $this->buildSandwichLayout($width, $body_rows);
        if ($layout === null) {
            return $this->padBodyLines([
                self::padOrShorten('  (window too narrow for flame view)', $width),
            ], $width, $body_rows);
        }

        $tree = $layout['tree'];
        $focus_count = max(1, $tree['focus_count']);
        $focus_count_f = (float)$focus_count;
        $inner_w = $layout['inner_w'];

        // Lazy-init the flame cursor on first render after a view
        // switch — we couldn't initialise it earlier because the
        // layout dimensions weren't known yet.
        if (
            $this->flame_cursor_visual_row < 0
            || $this->flame_cursor_x < 0
        ) {
            $this->flame_cursor_visual_row = $layout['focus_visual_row'];
            $this->flame_cursor_x = (int)floor($inner_w / 2);
        }
        $this->clampFlameCursor($layout);
        $cursor_bar = $this->findFlameCursorBar($layout);

        $no_line = $this->opts->no_line;
        $lines = [];
        $total_visual = $layout['total_visual_rows'];
        $focus_visual = $layout['focus_visual_row'];

        for ($vis = 0; $vis < $total_visual; $vis++) {
            if ($vis === $focus_visual) {
                $focus_label = $state->focus_label ?? '<none>';
                if ($this->flame_label_right_align) {
                    // Compact + shortenLeft so the focus banner truncation
                    // matches the bars: method:line stays visible, the
                    // namespace prefix is the first thing to drop.
                    $compact_focus = self::compactFlameLabel($focus_label);
                    $short_focus = self::shortenLeft($compact_focus, $inner_w - 4);
                    $focus_text = $short_focus . ' ◀ ';
                    $pad_len = max(0, $inner_w - mb_strlen($focus_text));
                    $padded = str_repeat(' ', $pad_len) . $focus_text;
                } else {
                    $focus_text = ' ▶ ' . $focus_label;
                    $padded = self::padOrShorten($focus_text, $inner_w);
                }
                $is_cursor_row = $this->flame_cursor_visual_row === $vis;
                // Cursor takes precedence over the regular yellow focus
                // banner so the user can see where the cursor is even
                // when it's parked on the focus bar.
                $lines[] = $is_cursor_row
                    ? "\e[1;7;46m" . $padded . "\e[0m"
                    : "\e[1;7;33m" . $padded . "\e[0m";
                continue;
            }
            $bars = $layout['visual_rows'][$vis] ?? [];
            $cursor_for_row = ($cursor_bar !== null
                && $this->flame_cursor_visual_row === $vis)
                ? $cursor_bar
                : null;
            $lines[] = $this->renderFlameRow($bars, $inner_w, $no_line, $cursor_for_row);
        }

        // 1-line cursor info footer (counts as a body row). Includes
        // both the raw sample count and the percentage of focus, so
        // the user can read absolute weight in addition to share.
        $cursor_label = $cursor_bar !== null
            ? Aggregator::labelFor($this->model, $cursor_bar['key_id'], $no_line)
            : '';
        $cursor_stats = $cursor_bar !== null
            ? sprintf(
                ' (%s · %.1f%%)',
                number_format($cursor_bar['count']),
                (float)$cursor_bar['count'] / $focus_count_f * 100.0,
            )
            : '';
        $cursor_room = max(0, $inner_w - mb_strlen($cursor_stats) - 3);
        $cursor_text = $cursor_label !== ''
            ? ' cursor: ' . self::shorten($cursor_label, $cursor_room - 8) . $cursor_stats
            : ' cursor: (none)';
        $lines[] = "\e[2m" . self::padOrShorten($cursor_text, $inner_w) . "\e[22m";

        return $this->padBodyLines($lines, $width, $body_rows);
    }

    /**
     * Pad / truncate a body lines array to exactly $body_rows entries.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private function padBodyLines(array $lines, int $width, int $body_rows): array
    {
        while (count($lines) < $body_rows) {
            $lines[] = str_repeat(' ', $width);
        }
        if (count($lines) > $body_rows) {
            $lines = array_slice($lines, 0, $body_rows);
        }
        return $lines;
    }

    /**
     * Render one row of the flame chart. Bars are drawn in reverse
     * video with alternating dim/bright so adjacent frames stay visually
     * distinct. The cursor bar (if any) overrides the dim/bright style
     * with a cyan background so it pops against everything else on the
     * row. Labels are written into bars wide enough to hold them
     * (≥ 4 cells); narrower bars become anonymous solid stripes.
     *
     * @param list<array{x:int, w:int, key_id:int, count:int}> $bars
     * @param array{x:int, w:int, key_id:int, count:int}|null  $cursor_bar
     */
    private function renderFlameRow(
        array $bars,
        int $width,
        bool $no_line,
        ?array $cursor_bar = null,
    ): string {
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
                if ($this->flame_label_right_align) {
                    // Right-align: keep "function:line" (drop the file
                    // path; the line number is short and useful), then
                    // shortenLeft so the rightmost part — the method
                    // name + line — stays visible at narrow widths.
                    // Pad on the LEFT with spaces so the label sits
                    // flush against the bar's right edge with a
                    // 1-cell trailing margin.
                    $compact = self::compactFlameLabel($label);
                    $short = self::shortenLeft($compact, $w - 1);
                    $pad_len = max(0, $w - mb_strlen($short) - 1);
                    $cell = str_repeat(' ', $pad_len) . $short . ' ';
                } else {
                    // Left-align: just the function name (drop the
                    // file:line tail entirely), shortened from the
                    // right so the namespace prefix stays visible.
                    $space_pos = strpos($label, ' ');
                    $short = $space_pos !== false
                        ? substr($label, 0, $space_pos)
                        : $label;
                    $short = self::shorten($short, $w - 1);
                    $cell = ' ' . $short;
                }
            } else {
                $cell = '';
            }
            $cell = self::padOrShorten($cell, $w);
            $is_cursor = $cursor_bar !== null
                && $bar['x'] === $cursor_bar['x']
                && $bar['w'] === $cursor_bar['w']
                && $bar['key_id'] === $cursor_bar['key_id'];
            if ($is_cursor) {
                $style = "\e[1;7;46m"; // bold reverse cyan — distinct from focus row's yellow
            } else {
                $style = ($i % 2) === 1 ? "\e[7;2m" : "\e[7m";
            }
            $out .= $style . $cell . "\e[0m";
            $cur += $w;
        }
        if ($cur < $width) {
            $out .= str_repeat(' ', $width - $cur);
        }
        return $out;
    }

    /**
     * Build the visual_row → bars mapping for the SandwichView::Flame
     * body at a given inner width / inner row count. Used by both the
     * renderer and the cursor navigation handlers (so cursor moves
     * can look up "what bars are at the row I'm trying to move to").
     *
     * Inner row layout: caller_rows + 1 focus row + callee_rows + 1
     * cursor-info footer = body_rows in total. So the layout claims
     * `body_rows - 1` rows for the actual flame chart.
     *
     * @return array{
     *   tree: array{
     *     focus_count:int,
     *     callers:array<int,array{count:int,children:array}>,
     *     callees:array<int,array{count:int,children:array}>,
     *   },
     *   visual_rows: array<int, list<array{x:int, w:int, key_id:int, count:int}>>,
     *   focus_visual_row: int,
     *   total_visual_rows: int,
     *   inner_w: int,
     * }|null
     */
    private function buildSandwichLayout(int $body_width, int $body_rows): ?array
    {
        $tree = $this->ensureSandwichTree();
        if ($tree === null) {
            return null;
        }
        if ($body_width < 20 || $body_rows < 5) {
            return null;
        }
        // 1 row focus + 1 row cursor footer = 2 reserved out of body_rows.
        // body_rows is always >= 5 after the MIN_ROWS guard, so
        // tree_rows is always >= 3 here.
        $tree_rows = $body_rows - 2;
        $caller_rows = (int)floor($tree_rows / 2);
        $callee_rows = $tree_rows - $caller_rows;
        $inner_w = $body_width;
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

        $state = $this->currentState();
        $focus_id = $state->focus_id ?? -1;

        $visual_rows = [];
        // Caller depths: deepest at top of popup (visual_row 0), depth 1
        // immediately above the focus row.
        for ($vis = 0; $vis < $caller_rows; $vis++) {
            $depth = $caller_rows - $vis;
            $visual_rows[$vis] = $caller_layout[$depth] ?? [];
        }
        // Focus row: 1 synthetic full-width bar so the cursor can
        // land on it and Enter is meaningful.
        $focus_visual_row = $caller_rows;
        $visual_rows[$focus_visual_row] = [[
            'x' => 0,
            'w' => $inner_w,
            'key_id' => $focus_id,
            'count' => $focus_count,
        ]];
        // Callee depths: depth 1 immediately under focus, deeper below.
        for ($vis = 0; $vis < $callee_rows; $vis++) {
            $depth = $vis + 1;
            $visual_rows[$focus_visual_row + 1 + $vis] = $callee_layout[$depth] ?? [];
        }

        return [
            'tree' => $tree,
            'visual_rows' => $visual_rows,
            'focus_visual_row' => $focus_visual_row,
            'total_visual_rows' => $caller_rows + 1 + $callee_rows,
            'inner_w' => $inner_w,
        ];
    }

    /**
     * Move the cursor one visual row in $dy direction. Uses the
     * x-preserving snap rule: if the new row contains a bar that
     * straddles the current cursor x, we land on it (cursor x stays);
     * otherwise we snap to the nearest bar's centre. The "skip empty
     * rows" loop handles the case where deeper levels of the tree
     * stop having any bar wide enough to render — we keep walking
     * until we hit a row with content or run off the edge.
     */
    private function moveFlameCursorRow(int $dy): void
    {
        $layout = $this->buildCurrentSandwichLayout();
        if ($layout === null) {
            return;
        }
        $total = $layout['total_visual_rows'];
        $row = $this->flame_cursor_visual_row + $dy;
        while ($row >= 0 && $row < $total) {
            $bars = $layout['visual_rows'][$row] ?? [];
            if ($bars !== []) {
                $hit = $this->findBarAtX($bars, $this->flame_cursor_x);
                if ($hit === null) {
                    $hit = $this->findNearestBar($bars, $this->flame_cursor_x);
                }
                $this->flame_cursor_visual_row = $row;
                if ($hit !== null) {
                    $outside = $this->flame_cursor_x < $hit['x']
                        || $this->flame_cursor_x >= $hit['x'] + $hit['w'];
                    if ($outside) {
                        $this->flame_cursor_x = $hit['x'] + intdiv($hit['w'], 2);
                    }
                }
                return;
            }
            $row += $dy;
        }
    }

    /**
     * Move the cursor to the previous/next bar at the current visual
     * row. Bars are sorted left-to-right; "next" wraps neither end.
     */
    private function moveFlameCursorSibling(int $direction): void
    {
        $layout = $this->buildCurrentSandwichLayout();
        if ($layout === null) {
            return;
        }
        $bars = $layout['visual_rows'][$this->flame_cursor_visual_row] ?? [];
        if ($bars === []) {
            return;
        }
        usort($bars, fn(array $a, array $b): int => $a['x'] <=> $b['x']);

        // Use the same right-inclusive lookup the row mover uses so
        // boundary cursors agree on which bar they're "on" — otherwise
        // ↑↓ and ←→ disagree about the current bar at boundaries and
        // ←→ feels unpredictable.
        $current = $this->findBarAtX($bars, $this->flame_cursor_x);
        if ($current === null) {
            $current = $this->findNearestBar($bars, $this->flame_cursor_x);
        }
        if ($current === null) {
            return;
        }
        $current_idx = -1;
        foreach ($bars as $i => $bar) {
            if (
                $bar['x'] === $current['x']
                && $bar['w'] === $current['w']
                && $bar['key_id'] === $current['key_id']
            ) {
                $current_idx = $i;
                break;
            }
        }
        if ($current_idx === -1) {
            return;
        }
        $new_idx = $current_idx + $direction;
        if ($new_idx < 0 || $new_idx >= count($bars)) {
            return;
        }
        $new_bar = $bars[$new_idx];
        $this->flame_cursor_x = $new_bar['x'] + intdiv($new_bar['w'], 2);
    }

    /**
     * Promote the cursor bar's frame in the flame body to a new
     * sandwich focus, staying in the same SandwichView::Flame view.
     * Mirrors focusSelected() for the regular panes.
     */
    private function focusFlameCursor(): void
    {
        $layout = $this->buildCurrentSandwichLayout();
        if ($layout === null) {
            return;
        }
        $cursor_bar = $this->findFlameCursorBar($layout);
        if ($cursor_bar === null) {
            return;
        }
        $kid = $cursor_bar['key_id'];
        if ($kid < 0) {
            return;
        }
        $state = $this->currentState();
        if ($kid === $state->focus_id) {
            // Cursor on the focus bar itself — no-op rather than push a
            // duplicate history entry.
            return;
        }
        $label = Aggregator::labelFor($this->model, $kid, $this->opts->no_line);
        $this->pushSandwichFocus($kid, $label, $state->sandwich_view);
    }

    /**
     * Push a new sandwich state with the given focus and view, taking
     * care to snapshot the outgoing overview cursor and reset the
     * per-view cursors so the user lands on a sensible default for
     * whichever view they end up in.
     */
    private function pushSandwichFocus(int $key_id, string $label, SandwichView $view): void
    {
        $state = $this->currentState();
        $this->replaceTop($state->withOverviewCursor(
            $this->overview_selected,
            $this->overview_top_row,
        ));
        $this->stack[] = new ViewState(
            mode: ExploreMode::Sandwich,
            sandwich_view: $view,
            focus_id: $key_id,
            focus_label: $label,
            active_pane: $state->active_pane,
            overview_selected: $this->overview_selected,
            overview_top_row: $this->overview_top_row,
        );
        $this->resetScrolls();
        $this->resetSandwichViewCursors();
        $this->invalidate();
    }

    /**
     * Reset the per-SandwichView cursor states (flame + tree). Called
     * when pushing a new focus or when switching SandwichView in
     * place, so the user always lands at a sensible default in the
     * new view.
     */
    private function resetSandwichViewCursors(): void
    {
        // Sentinel for the flame view: lazy-init to focus row centre
        // on the next render once we know the layout dimensions.
        $this->flame_cursor_visual_row = -1;
        $this->flame_cursor_x = -1;
        $this->tree_top_row = 0;
        $this->tree_cursor_row = 0;
        // The fold state is per-(focus, direction) — clearing it here
        // covers both "switching focus" and "switching tree direction
        // on the same focus" since the underlying tree shape changes
        // in either case and the old paths become meaningless.
        $this->tree_folded = [];
    }

    /**
     * Convenience: build the sandwich layout at the current
     * terminal-derived body width / body rows. Used by every flame
     * cursor handler so they all see the same geometry the renderer
     * just painted.
     *
     * @return array{
     *   tree: array{
     *     focus_count:int,
     *     callers:array<int,array{count:int,children:array}>,
     *     callees:array<int,array{count:int,children:array}>,
     *   },
     *   visual_rows: array<int, list<array{x:int, w:int, key_id:int, count:int}>>,
     *   focus_visual_row: int,
     *   total_visual_rows: int,
     *   inner_w: int,
     * }|null
     */
    private function buildCurrentSandwichLayout(): ?array
    {
        [$cols, $rows] = $this->term->size();
        $body_rows = $this->computeBodyRows($cols, $rows);
        $body_width = $this->computeBodyWidth($cols);
        return $this->buildSandwichLayout($body_width, $body_rows);
    }

    /**
     * Mirror of the body_rows computation in render() — kept in one
     * place so cursor handlers and the renderer agree on geometry.
     */
    private function computeBodyRows(int $cols, int $rows): int
    {
        $state = $this->currentState();
        $show_mini_flame = $state->mode === ExploreMode::Sandwich
            && $this->mini_flame_enabled;
        return max(3, $rows - 6 - ($show_mini_flame ? 1 : 0));
    }

    /**
     * Mirror of the body_width computation in render().
     */
    private function computeBodyWidth(int $cols): int
    {
        $state = $this->currentState();
        $show_sidebar = $state->mode === ExploreMode::Sandwich
            && $this->shouldShowSidebar($cols);
        $sidebar_width = $show_sidebar ? $this->computeSidebarWidth($cols) : 0;
        $separator_width = $show_sidebar ? 1 : 0;
        return $cols - $sidebar_width - $separator_width;
    }

    /**
     * Defend the cursor against terminal resizes between key presses
     * by re-snapping it to a real bar at its current row.
     *
     * @param array{
     *   visual_rows: array<int, list<array{x:int, w:int, key_id:int, count:int}>>,
     *   total_visual_rows: int,
     *   inner_w: int,
     *   focus_visual_row: int,
     *   tree: array,
     * } $layout
     */
    private function clampFlameCursor(array $layout): void
    {
        $total = $layout['total_visual_rows'];
        if ($total <= 0) {
            return;
        }
        if ($this->flame_cursor_visual_row < 0) {
            $this->flame_cursor_visual_row = 0;
        }
        if ($this->flame_cursor_visual_row >= $total) {
            $this->flame_cursor_visual_row = $total - 1;
        }
        $inner_w = $layout['inner_w'];
        if ($this->flame_cursor_x < 0) {
            $this->flame_cursor_x = 0;
        }
        if ($this->flame_cursor_x >= $inner_w) {
            $this->flame_cursor_x = $inner_w - 1;
        }
    }

    /**
     * @param array{visual_rows: array<int, list<array{x:int, w:int, key_id:int, count:int}>>, ...} $layout
     * @return array{x:int, w:int, key_id:int, count:int}|null
     */
    private function findFlameCursorBar(array $layout): ?array
    {
        $bars = $layout['visual_rows'][$this->flame_cursor_visual_row] ?? [];
        if ($bars === []) {
            return null;
        }
        return $this->findBarAtX($bars, $this->flame_cursor_x)
            ?? $this->findNearestBar($bars, $this->flame_cursor_x);
    }

    /**
     * Look up the bar at column $x. The interval is right-INCLUSIVE
     * (`<= bar['x'] + bar['w']`) so that at a shared boundary the
     * left bar wins. The bar list arrives in layout order
     * (left-to-right == count-descending), so iteration picks the
     * left = larger bar at any tie. Without this, descending from
     * the centre of a parent bar into its children drifted right
     * whenever the largest child took up exactly half the parent —
     * a common case (e.g. one dominant call path) — and the cursor
     * would land on the second-largest sibling instead of the first.
     *
     * @param  list<array{x:int, w:int, key_id:int, count:int}> $bars
     * @return array{x:int, w:int, key_id:int, count:int}|null
     */
    private function findBarAtX(array $bars, int $x): ?array
    {
        foreach ($bars as $bar) {
            if ($x >= $bar['x'] && $x <= $bar['x'] + $bar['w']) {
                return $bar;
            }
        }
        return null;
    }

    /**
     * @param  list<array{x:int, w:int, key_id:int, count:int}> $bars
     * @return array{x:int, w:int, key_id:int, count:int}|null
     */
    private function findNearestBar(array $bars, int $x): ?array
    {
        if ($bars === []) {
            return null;
        }
        $best = null;
        $best_dist = PHP_INT_MAX;
        foreach ($bars as $bar) {
            $center = $bar['x'] + intdiv($bar['w'], 2);
            $dist = abs($center - $x);
            if ($dist < $best_dist) {
                $best_dist = $dist;
                $best = $bar;
            }
        }
        return $best;
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
     * Recursive walk over the SandwichBuilder forest. The shape of
     * each node is `array{count:int, children:array<array-key, mixed>}`
     * and the recursion is on `children`. The recursive descent matches
     * the SandwichBuilder shape but Psalm can't statically reify it
     * (same reason {@see SandwichBuilder::build()} suppresses Mixed*),
     * so the call site below also passes the shape opaquely.
     *
     * @param array<int, array{count:int, children:array<array-key, mixed>}> $nodes
     * @param array<int, list<array{x:int, w:int, key_id:int, count:int}>>   $rows
     * @psalm-suppress MixedArgumentTypeCoercion
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
            $w = (int)floor((float)$node['count'] / (float)$denom * (float)$width);
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
     * Soft cap on the total number of rows the tree popup will build.
     * Real focus trees can have thousands of nodes; capping the walk
     * keeps the worst case bounded so a key press never has to chew
     * through millions of nodes. Tail beyond the cap is silently
     * truncated.
     */
    private const TREE_BODY_MAX_LINES = 5000;

    /**
     * Inline indented-tree body for SandwichView::TreeCallees /
     * TreeCallers.
     *
     * The flame body is great for "where is the mass" but loses frame
     * names in narrow bars; this view is the inverse — full, readable
     * labels at the cost of needing to scroll vertically to see the
     * long tail. Each row carries a Braille percentage bar and the
     * share-of-focus number, so the relative size of nested frames is
     * still legible at a glance. The cursor row is rendered in
     * reverse video; ↑↓/PgUp/PgDn/Home/End scroll it, Enter promotes
     * the cursor frame to a new focus inside the same view.
     *
     * Reuses {@see ensureSandwichTree} so switching between flame /
     * tree views on the same focus is free (the sample-stack walk
     * only runs once per focus / no_line / match_re tuple).
     *
     * @param 'callees'|'callers' $direction
     * @return list<string> exactly $body_rows lines, padded as needed
     */
    private function renderSandwichTreeBodyLines(
        ViewState $state,
        string $direction,
        int $width,
        int $body_rows,
    ): array {
        if ($state->focus_id === null) {
            return $this->padBodyLines([], $width, $body_rows);
        }
        $entries = $this->buildSandwichTreeLines($direction, $width);
        $total = count($entries);

        // 1 row footer reserved out of body_rows; rest are tree rows.
        $tree_rows = max(1, $body_rows - 1);

        if ($total === 0) {
            $this->tree_cursor_row = 0;
            $this->tree_top_row = 0;
        } else {
            if ($this->tree_cursor_row < 0) {
                $this->tree_cursor_row = 0;
            }
            if ($this->tree_cursor_row >= $total) {
                $this->tree_cursor_row = $total - 1;
            }
            if ($this->tree_cursor_row < $this->tree_top_row) {
                $this->tree_top_row = $this->tree_cursor_row;
            }
            if ($this->tree_cursor_row >= $this->tree_top_row + $tree_rows) {
                $this->tree_top_row = $this->tree_cursor_row - $tree_rows + 1;
            }
            if ($this->tree_top_row < 0) {
                $this->tree_top_row = 0;
            }
            $max_top = max(0, $total - $tree_rows);
            if ($this->tree_top_row > $max_top) {
                $this->tree_top_row = $max_top;
            }
        }

        $lines = [];
        for ($i = 0; $i < $tree_rows; $i++) {
            $row_idx = $this->tree_top_row + $i;
            if (!isset($entries[$row_idx])) {
                $lines[] = str_repeat(' ', $width);
                continue;
            }
            $line = self::padOrShorten($entries[$row_idx]['text'], $width);
            if ($row_idx === $this->tree_cursor_row) {
                $line = "\e[7m" . $line . "\e[27m";
            }
            $lines[] = $line;
        }

        $position = $total > 0
            ? sprintf('%d/%d', $this->tree_cursor_row + 1, $total)
            : '0/0';
        $footer = sprintf(
            ' %s · %s ',
            $direction === 'callees' ? 'toward leaves' : 'toward roots',
            $position,
        );
        $lines[] = "\e[2m" . self::padOrShorten($footer, $width) . "\e[22m";

        return $this->padBodyLines($lines, $width, $body_rows);
    }

    /**
     * Build the full flat line list for the sandwich tree body. Each
     * entry carries the rendered text, the key_id of the frame (so the
     * cursor handler / Enter can re-focus on the cursor row), and the
     * synthetic path string used by the fold/unfold tracking.
     *
     * @param 'callees'|'callers' $direction
     * @return list<array{text:string, key_id:int, path:string}>
     */
    private function buildSandwichTreeLines(string $direction, int $width): array
    {
        $tree = $this->ensureSandwichTree();
        if ($tree === null) {
            return [];
        }
        $children = $direction === 'callees' ? $tree['callees'] : $tree['callers'];
        $denom = max(1, $tree['focus_count']);
        $entries = [];
        $this->walkTreeForBody(
            $children,
            $denom,
            '',
            $width,
            self::TREE_BODY_MAX_LINES,
            $entries,
            $this->opts->no_line,
        );
        return $entries;
    }

    /**
     * DFS-walk the sandwich tree producing one indented entry per node.
     * Stops once $max_lines entries have been emitted.
     *
     * Each row layout:
     *   {prefix}{connector}{fold_marker}{braille_bar} {pct%} {label} ({count})
     *
     * The fold marker is `▶ ` for a folded subtree, `▼ ` for an
     * unfolded node with children, and `  ` (2 spaces) for a leaf —
     * keeping every row the same width regardless of state.
     *
     * The current path (slash-joined chain of key_ids from depth-1
     * down) is propagated through the recursion so the fold-state
     * lookup and the per-entry path tag are both available without
     * a second walk.
     *
     * @param array<int, array{count:int, children:array<array-key, mixed>}> $nodes
     * @param list<array{text:string, key_id:int, path:string}>               $entries
     * @psalm-suppress MixedArgumentTypeCoercion recursive shape lost on `children`
     */
    private function walkTreeForBody(
        array $nodes,
        int $denom,
        string $prefix,
        int $width,
        int $max_lines,
        array &$entries,
        bool $no_line,
        string $current_path = '',
    ): void {
        if ($nodes === [] || count($entries) >= $max_lines) {
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

        $kids = array_keys($nodes);
        $n = count($kids);
        for ($i = 0; $i < $n; $i++) {
            if (count($entries) >= $max_lines) {
                return;
            }
            $kid = $kids[$i];
            /** @var array{count:int, children:array} $node */
            $node = $nodes[$kid];
            $is_last = ($i === $n - 1);
            $connector = $is_last ? '└─ ' : '├─ ';

            $node_path = $current_path === ''
                ? (string)$kid
                : $current_path . '/' . $kid;
            $has_children = $node['children'] !== [];
            $is_folded = isset($this->tree_folded[$node_path]);
            $marker = $has_children
                ? ($is_folded ? '▶ ' : '▼ ')
                : '  ';

            $ratio = (float)$node['count'] / (float)$denom;
            $bar = self::brailleHBar($ratio, 6);
            $label = Aggregator::labelFor($this->model, $kid, $no_line);

            $head = sprintf(
                '%s%s%s%s %5.1f%% ',
                $prefix,
                $connector,
                $marker,
                $bar,
                $ratio * 100.0,
            );
            $tail = sprintf(' (%s)', number_format($node['count']));
            $room = max(1, $width - mb_strlen($head) - mb_strlen($tail));
            $text = $head . self::shorten($label, $room) . $tail;
            $entries[] = [
                'text' => $text,
                'key_id' => $kid,
                'path' => $node_path,
            ];

            if ($is_folded || !$has_children) {
                continue;
            }

            $next_prefix = $prefix . ($is_last ? '   ' : '│  ');
            $this->walkTreeForBody(
                $node['children'],
                $denom,
                $next_prefix,
                $width,
                $max_lines,
                $entries,
                $no_line,
                $node_path,
            );
        }
    }

    /**
     * Promote the cursor row's frame in the tree body to a new
     * sandwich focus, staying in the same tree view direction.
     */
    private function focusTreeCursor(): void
    {
        $state = $this->currentState();
        if ($state->mode !== ExploreMode::Sandwich) {
            return;
        }
        $direction = $state->sandwich_view === SandwichView::TreeCallees
            ? 'callees'
            : 'callers';
        $entries = $this->buildSandwichTreeLines($direction, 4096);
        if ($entries === []) {
            $this->status = 'tree: empty';
            return;
        }
        $cursor = $this->tree_cursor_row;
        if ($cursor < 0) {
            $cursor = 0;
        }
        if ($cursor >= count($entries)) {
            $cursor = count($entries) - 1;
        }
        $kid = $entries[$cursor]['key_id'];
        if ($kid < 0) {
            $this->status = 'cannot focus synthetic <root>/<leaf>';
            return;
        }
        if ($kid === $state->focus_id) {
            return;
        }
        $label = Aggregator::labelFor($this->model, $kid, $this->opts->no_line);
        $this->pushSandwichFocus($kid, $label, $state->sandwich_view);
    }

    /**
     * Render a horizontal Braille bar of $cells width representing
     * $ratio in [0..1]. Each cell carries 2 horizontal sub-pixels so
     * the smallest visible step is 1/(2*$cells) of the bar.
     */
    private static function brailleHBar(float $ratio, int $cells): string
    {
        if ($ratio < 0) {
            $ratio = 0.0;
        }
        if ($ratio > 1) {
            $ratio = 1.0;
        }
        $sub = (int)round($ratio * (float)$cells * 2.0);
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

    /**
     * View-aware UP/DOWN/PgUp/PgDn dispatcher. The behaviour depends
     * on the current sandwich_view (and falls through to the regular
     * moveSelection() for list mode and the panes view).
     */
    private function dispatchUpDown(int $delta): void
    {
        $state = $this->currentState();
        if ($state->mode !== ExploreMode::Sandwich) {
            $this->moveSelection($delta);
            return;
        }
        if ($state->active_pane === ActivePane::Focus) {
            // Focus banner has no scroll content; ↑/↓ jump to the
            // adjacent pane (visually: callers above, callees below).
            // Page-step (delta = ±10) is treated the same — there's
            // nowhere "further" to go on the focus row.
            if ($delta < 0) {
                $this->replaceTop($state->withActivePane(ActivePane::Callers));
            } elseif ($delta > 0) {
                $this->replaceTop($state->withActivePane(ActivePane::Callees));
            }
            return;
        }
        if ($state->active_pane === ActivePane::Overview) {
            $this->moveSelection($delta);
            return;
        }
        switch ($state->sandwich_view) {
            case SandwichView::Panes:
                $this->moveSelection($delta);
                return;
            case SandwichView::Flame:
                $this->moveFlameCursorRow($delta > 0 ? 1 : -1);
                return;
            case SandwichView::TreeCallees:
            case SandwichView::TreeCallers:
                $this->tree_cursor_row += $delta;
                return;
        }
    }

    /**
     * View-aware HOME/END dispatcher.
     */
    private function dispatchHomeEnd(bool $end): void
    {
        $state = $this->currentState();
        if ($state->mode !== ExploreMode::Sandwich) {
            $this->setSelection($end ? PHP_INT_MAX : 0);
            return;
        }
        if ($state->active_pane === ActivePane::Focus) {
            // Nothing to "go to the start/end of" on the focus banner.
            return;
        }
        if ($state->active_pane === ActivePane::Overview) {
            $this->setSelection($end ? PHP_INT_MAX : 0);
            return;
        }
        switch ($state->sandwich_view) {
            case SandwichView::Panes:
                $this->setSelection($end ? PHP_INT_MAX : 0);
                return;
            case SandwichView::Flame:
                // Flame layout has no obvious "first/last bar" semantics
                // — punt to a no-op rather than picking arbitrarily.
                return;
            case SandwichView::TreeCallees:
            case SandwichView::TreeCallers:
                $this->tree_cursor_row = $end ? PHP_INT_MAX : 0;
                return;
        }
    }

    /**
     * View-aware Enter dispatcher.
     */
    private function dispatchEnter(): void
    {
        $state = $this->currentState();
        if ($state->mode !== ExploreMode::Sandwich) {
            $this->focusSelected();
            return;
        }
        if ($state->active_pane === ActivePane::Focus) {
            // Cursor on the focus banner: drilling into yourself
            // would just push a duplicate. Status hint and bail.
            $this->status = 'already focused';
            return;
        }
        if ($state->active_pane === ActivePane::Overview) {
            $this->focusSelected();
            return;
        }
        switch ($state->sandwich_view) {
            case SandwichView::Panes:
                $this->focusSelected();
                return;
            case SandwichView::Flame:
                $this->focusFlameCursor();
                return;
            case SandwichView::TreeCallees:
            case SandwichView::TreeCallers:
                $this->focusTreeCursor();
                return;
        }
    }

    /**
     * View-aware ←/→ dispatcher. In panes view (and list mode) these
     * keys still mean "switch active pane", matching the original
     * keybinding. In flame view they move the cursor between sibling
     * bars; in tree view they fold (←) / unfold (→) the cursor row's
     * subtree.
     */
    private function dispatchLeftRight(int $direction): void
    {
        $state = $this->currentState();
        if ($state->mode !== ExploreMode::Sandwich) {
            return;
        }
        if ($state->sandwich_view === SandwichView::Flame) {
            if ($state->active_pane === ActivePane::Overview) {
                return;
            }
            $this->moveFlameCursorSibling($direction);
            return;
        }
        if (
            $state->sandwich_view === SandwichView::TreeCallees
            || $state->sandwich_view === SandwichView::TreeCallers
        ) {
            if ($state->active_pane === ActivePane::Overview) {
                return;
            }
            if ($direction < 0) {
                $this->foldTreeAtCursor(false);
            } else {
                $this->unfoldTreeAtCursor(false);
            }
            return;
        }
        // Only SandwichView::Panes is left here.
        $this->replaceTop(
            $state->withActivePane($direction < 0 ? ActivePane::Callers : ActivePane::Callees)
        );
    }

    /**
     * Mark the cursor row's subtree as folded. With $recursive=true,
     * also marks every descendant subtree as folded so unfolding the
     * cursor later only reveals immediate children — useful when you
     * want to fully collapse a branch.
     *
     * Cursor stays on the same node since folding only affects
     * descendants — the cursor row itself is still in the entries list
     * at the same logical position (its row index may shift forward
     * if there were folded entries above the cursor, but the helper
     * leaves $tree_cursor_row alone for the simple case; the next
     * render's clamp pass will sort out the resulting position).
     */
    private function foldTreeAtCursor(bool $recursive): void
    {
        $state = $this->currentState();
        if ($state->mode !== ExploreMode::Sandwich) {
            return;
        }
        if (
            $state->sandwich_view !== SandwichView::TreeCallees
            && $state->sandwich_view !== SandwichView::TreeCallers
        ) {
            return;
        }
        $direction = $state->sandwich_view === SandwichView::TreeCallees
            ? 'callees'
            : 'callers';
        $entries = $this->buildSandwichTreeLines($direction, 4096);
        if ($entries === []) {
            return;
        }
        $cursor = $this->tree_cursor_row;
        if ($cursor < 0 || $cursor >= count($entries)) {
            return;
        }
        $path = $entries[$cursor]['path'];
        if ($path === '') {
            return;
        }
        $this->tree_folded[$path] = true;

        if ($recursive) {
            // Mark every descendant of $path too. Walking the raw
            // sandwich tree (rather than re-walking the entries list,
            // which would already exclude the folded subtree) gives
            // us the full descendant set in one pass.
            $tree = $this->ensureSandwichTree();
            if ($tree !== null) {
                $children = $direction === 'callees'
                    ? $tree['callees']
                    : $tree['callers'];
                $sub = $this->findTreeNodeByPath($children, $path);
                if ($sub !== null && $sub['children'] !== []) {
                    /** @psalm-suppress MixedArgumentTypeCoercion subtree shape lost via findTreeNodeByPath return */
                    $this->collectAllPaths($sub['children'], $path, $this->tree_folded);
                }
            }
        }
    }

    /**
     * Unfold the cursor row's subtree (one level — children become
     * visible but their own children inherit whatever fold state
     * they previously had). With $recursive=true, also clears every
     * descendant fold entry, fully expanding the subtree.
     */
    private function unfoldTreeAtCursor(bool $recursive): void
    {
        $state = $this->currentState();
        if ($state->mode !== ExploreMode::Sandwich) {
            return;
        }
        if (
            $state->sandwich_view !== SandwichView::TreeCallees
            && $state->sandwich_view !== SandwichView::TreeCallers
        ) {
            return;
        }
        $direction = $state->sandwich_view === SandwichView::TreeCallees
            ? 'callees'
            : 'callers';
        $entries = $this->buildSandwichTreeLines($direction, 4096);
        if ($entries === []) {
            return;
        }
        $cursor = $this->tree_cursor_row;
        if ($cursor < 0 || $cursor >= count($entries)) {
            return;
        }
        $path = $entries[$cursor]['path'];
        if ($path === '') {
            return;
        }
        unset($this->tree_folded[$path]);

        if ($recursive) {
            $prefix = $path . '/';
            // PHP coerces purely-numeric string array keys to int, so a
            // depth-1 path like "42" comes back from array_keys() as
            // the integer 42. Cast every key to string before the
            // prefix check; the unset uses the original (possibly int)
            // key, which PHP will normalise on the way back in.
            foreach (array_keys($this->tree_folded) as $folded_path) {
                if (str_starts_with((string)$folded_path, $prefix)) {
                    unset($this->tree_folded[$folded_path]);
                }
            }
        }
    }

    /**
     * Walk the raw sandwich tree to find the node at $path.
     *
     * @param  array<int, array{count:int, children:array<array-key, mixed>}> $nodes
     * @return array{count:int, children:array<array-key, mixed>}|null
     * @psalm-suppress MixedArgumentTypeCoercion recursive shape lost on `children`
     */
    private function findTreeNodeByPath(array $nodes, string $path): ?array
    {
        $segments = explode('/', $path);
        $cur_nodes = $nodes;
        $found = null;
        foreach ($segments as $seg) {
            $kid = (int)$seg;
            if (!isset($cur_nodes[$kid])) {
                return null;
            }
            /** @var array{count:int, children:array} $node */
            $node = $cur_nodes[$kid];
            $found = $node;
            $cur_nodes = $node['children'];
        }
        return $found;
    }

    /**
     * Collect every descendant path of $base_path into $into. Used
     * by recursive fold (where we want to mark every descendant
     * subtree as folded too).
     *
     * @param array<int, array{count:int, children:array<array-key, mixed>}> $nodes
     * @param array<array-key, true>                                          $into
     * @psalm-suppress MixedArgumentTypeCoercion recursive shape lost on `children`
     */
    private function collectAllPaths(array $nodes, string $base_path, array &$into): void
    {
        foreach ($nodes as $kid => $node) {
            $node_path = $base_path . '/' . $kid;
            if ($node['children'] !== []) {
                $into[$node_path] = true;
                $this->collectAllPaths($node['children'], $node_path, $into);
            }
        }
    }

    /**
     * Switch the current sandwich state's display variant. If the
     * cursor's current frame in the OUTGOING view is something other
     * than the focus, treat the switch as a drill-down too: push a
     * new sandwich state with that frame as the focus and the new
     * variant. Otherwise just rewrite the current state's
     * sandwich_view in place.
     *
     * This is what makes mode-switching keys feel like "open the
     * other view ON the thing I'm currently looking at".
     */
    private function switchSandwichView(SandwichView $new_view): void
    {
        $state = $this->currentState();
        if ($state->mode !== ExploreMode::Sandwich || $state->focus_id === null) {
            $this->status = 'switch view: focus a frame first';
            return;
        }
        $cursor = $this->getActiveCursorFrame();
        if ($cursor !== null && $cursor['key_id'] !== $state->focus_id) {
            $this->pushSandwichFocus($cursor['key_id'], $cursor['label'], $new_view);
            return;
        }
        $this->replaceTop($state->withSandwichView($new_view));
        $this->resetSandwichViewCursors();
    }

    /**
     * Read the (key_id, label) of whatever the active cursor in the
     * current view is currently pointing at, or null if it's on a
     * synthetic / out-of-range slot. Used by switchSandwichView so
     * a mode change can carry the cursor frame across as the new
     * focus.
     *
     * @return array{key_id:int, label:string}|null
     */
    private function getActiveCursorFrame(): ?array
    {
        $state = $this->currentState();
        if ($state->mode !== ExploreMode::Sandwich) {
            $rows = $this->applyViewFilter(
                $this->ensureList()['rows'],
                $state->view_filter,
            );
            if (!isset($rows[$this->list_selected])) {
                return null;
            }
            [, $key_id, $label] = $rows[$this->list_selected];
            return $key_id < 0 ? null : ['key_id' => $key_id, 'label' => $label];
        }
        if ($state->active_pane === ActivePane::Overview) {
            $rows = $this->ensureOverview()['rows'];
            if (!isset($rows[$this->overview_selected])) {
                return null;
            }
            [, $key_id, $label] = $rows[$this->overview_selected];
            return $key_id < 0 ? null : ['key_id' => $key_id, 'label' => $label];
        }
        if ($state->active_pane === ActivePane::Focus) {
            // Cursor parked on the focus banner: the "current frame"
            // IS the focus, so view-switching keys see them as equal
            // and treat the switch as in-place rather than a drilldown.
            if ($state->focus_id === null || $state->focus_id < 0) {
                return null;
            }
            return [
                'key_id' => $state->focus_id,
                'label' => $state->focus_label ?? '',
            ];
        }
        switch ($state->sandwich_view) {
            case SandwichView::Panes:
                $rows = match ($state->active_pane) {
                    ActivePane::Callers => $this->applyViewFilter(
                        $this->ensureCallers()['rows'],
                        $state->view_filter,
                    ),
                    ActivePane::Callees => $this->applyViewFilter(
                        $this->ensureCallees()['rows'],
                        $state->view_filter,
                    ),
                    ActivePane::Overview, ActivePane::Focus => [],
                };
                $idx = match ($state->active_pane) {
                    ActivePane::Callers  => $this->callers_selected,
                    ActivePane::Callees  => $this->callees_selected,
                    ActivePane::Overview, ActivePane::Focus => 0,
                };
                if (!isset($rows[$idx])) {
                    return null;
                }
                [, $key_id, $label] = $rows[$idx];
                return $key_id < 0 ? null : ['key_id' => $key_id, 'label' => $label];

            case SandwichView::Flame:
                $layout = $this->buildCurrentSandwichLayout();
                if ($layout === null) {
                    return null;
                }
                $bar = $this->findFlameCursorBar($layout);
                if ($bar === null || $bar['key_id'] < 0) {
                    return null;
                }
                $label = Aggregator::labelFor(
                    $this->model,
                    $bar['key_id'],
                    $this->opts->no_line,
                );
                return ['key_id' => $bar['key_id'], 'label' => $label];

            case SandwichView::TreeCallees:
            case SandwichView::TreeCallers:
                $direction = $state->sandwich_view === SandwichView::TreeCallees
                    ? 'callees'
                    : 'callers';
                $entries = $this->buildSandwichTreeLines($direction, 4096);
                if ($entries === []) {
                    return null;
                }
                $idx = $this->tree_cursor_row;
                if ($idx < 0) {
                    $idx = 0;
                }
                if ($idx >= count($entries)) {
                    $idx = count($entries) - 1;
                }
                $kid = $entries[$idx]['key_id'];
                if ($kid < 0) {
                    return null;
                }
                $label = Aggregator::labelFor($this->model, $kid, $this->opts->no_line);
                return ['key_id' => $kid, 'label' => $label];
        }
        return null;
    }

    private function dispatch(string $key): void
    {
        if ($this->help_open) {
            $this->help_open = false;
            return;
        }
        if ($this->prompt_label !== null) {
            $this->dispatchPrompt($key);
            return;
        }

        $mouse = MouseEvent::tryParse($key);
        if ($mouse !== null) {
            $this->dispatchMouse($mouse);
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
                $this->dispatchUpDown(-1);
                return;

            case Keymap::ACTION_DOWN:
                $this->dispatchUpDown(1);
                return;

            case Keymap::ACTION_PAGE_UP:
                $this->dispatchUpDown(-10);
                return;

            case Keymap::ACTION_PAGE_DOWN:
                $this->dispatchUpDown(10);
                return;

            case Keymap::ACTION_HOME:
                $this->dispatchHomeEnd(false);
                return;

            case Keymap::ACTION_END:
                $this->dispatchHomeEnd(true);
                return;

            case Keymap::ACTION_FOCUS_ENTER:
                $this->dispatchEnter();
                return;

            case Keymap::ACTION_TOGGLE_PANE:
                if ($state->mode === ExploreMode::Sandwich) {
                    $next = $state->active_pane->next();
                    // Focus pane only makes sense in panes view
                    // (flame / tree views render the focus inline as
                    // part of their body, so there's no separate
                    // banner to land on); skip past it elsewhere.
                    if (
                        $next === ActivePane::Focus
                        && $state->sandwich_view !== SandwichView::Panes
                    ) {
                        $next = $next->next();
                    }
                    $this->replaceTop($state->withActivePane($next));
                }
                return;

            case Keymap::ACTION_TOGGLE_PANE_REVERSE:
                if ($state->mode === ExploreMode::Sandwich) {
                    $prev = $state->active_pane->prev();
                    if (
                        $prev === ActivePane::Focus
                        && $state->sandwich_view !== SandwichView::Panes
                    ) {
                        $prev = $prev->prev();
                    }
                    $this->replaceTop($state->withActivePane($prev));
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

            case Keymap::ACTION_VIEW_PANES:
                $this->switchSandwichView(SandwichView::Panes);
                return;

            case Keymap::ACTION_VIEW_FLAME:
                $this->switchSandwichView(SandwichView::Flame);
                return;

            case Keymap::ACTION_VIEW_TREE_CALLEES:
                $this->switchSandwichView(SandwichView::TreeCallees);
                return;

            case Keymap::ACTION_VIEW_TREE_CALLERS:
                $this->switchSandwichView(SandwichView::TreeCallers);
                return;

            case Keymap::ACTION_TREE_FOLD_RECURSIVE:
                $this->foldTreeAtCursor(true);
                return;

            case Keymap::ACTION_TREE_UNFOLD_RECURSIVE:
                $this->unfoldTreeAtCursor(true);
                return;

            case Keymap::ACTION_TOGGLE_FLAME_LABEL_ALIGN:
                $this->flame_label_right_align = !$this->flame_label_right_align;
                $this->status = 'flame label align: '
                    . ($this->flame_label_right_align ? 'right' : 'left');
                return;

            case Keymap::ACTION_CALLERS:
                $this->dispatchLeftRight(-1);
                return;

            case Keymap::ACTION_CALLEES:
                $this->dispatchLeftRight(+1);
                return;

            case Keymap::ACTION_BACK:
                $this->popFocus();
                return;

            case Keymap::ACTION_VIEW_SELF:
                if ($state->mode === ExploreMode::Sandwich) {
                    $this->setOverviewSort('self');
                    $this->replaceTop($state->withActivePane(ActivePane::Overview));
                    return;
                }
                $this->resetTo(ExploreMode::ListSelf);
                return;

            case Keymap::ACTION_VIEW_TOTAL:
                if ($state->mode === ExploreMode::Sandwich) {
                    $this->setOverviewSort('total');
                    $this->replaceTop($state->withActivePane(ActivePane::Overview));
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
                        $this->replaceTop($state->withViewFilter($value));
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

    /**
     * Handle a parsed SGR mouse event.
     *
     * Supported interactions:
     *   - Scroll wheel: scroll the pane under the mouse pointer
     *   - Left-click on a body row → move selection to that row
     *   - Left-click on sidebar (overview) → activate + select row
     *   - Left-click on sandwich pane → activate that pane + select
     */
    private function dispatchMouse(MouseEvent $mouse): void
    {
        // Only act on press events (ignore release / drag).
        if (!$mouse->press) {
            return;
        }

        $is_scroll = $mouse->button === MouseEvent::SCROLL_UP
            || $mouse->button === MouseEvent::SCROLL_DOWN;

        // For non-scroll events, only left-click is handled.
        if (!$is_scroll && $mouse->button !== MouseEvent::BUTTON_LEFT) {
            return;
        }

        // Convert 1-based ANSI coords to 0-based screen row/col.
        $screen_row = $mouse->row - 1;
        $screen_col = $mouse->col - 1;

        [$cols, $rows] = $this->term->size();
        if ($cols < self::MIN_COLS || $rows < self::MIN_ROWS) {
            return;
        }

        $state = $this->currentState();
        $show_mini_flame = $state->mode === ExploreMode::Sandwich
            && $this->mini_flame_enabled;
        $body_rows = $rows - 6 - ($show_mini_flame ? 1 : 0);
        $body_start = 4 + ($show_mini_flame ? 1 : 0);

        // [δN] indicator on header line 2 (screen row 2).
        if (
            $screen_row === 2
            && $screen_col >= $this->scroll_delta_cols[0]
            && $screen_col < $this->scroll_delta_cols[1]
        ) {
            $this->dispatchScrollDeltaWidget($is_scroll, $mouse);
            return;
        }

        // Click on the mini-flame strip row (directly after the header).
        if ($show_mini_flame && $screen_row === 4 && !$is_scroll) {
            $this->dispatchMouseMiniFlame($screen_col, $cols);
            return;
        }

        if ($screen_row < $body_start || $screen_row >= $body_start + $body_rows) {
            return;
        }

        $body_row = $screen_row - $body_start;

        $show_sidebar = $state->mode === ExploreMode::Sandwich
            && $this->shouldShowSidebar($cols);
        $sidebar_width = $show_sidebar ? $this->computeSidebarWidth($cols) : 0;

        // Determine which pane the pointer is over and dispatch.
        $hover_pane = $this->hitTestPane($state, $body_row, $body_rows, $screen_col, $sidebar_width);

        if ($is_scroll) {
            $step = $this->scroll_delta;
            $delta = $mouse->button === MouseEvent::SCROLL_UP ? -$step : $step;
            $this->dispatchMouseScroll($state, $hover_pane, $delta);
            return;
        }

        // Left-click — detect double-click for focus/enter action.
        $now = microtime(true);
        $is_double = ($now - $this->last_click_time) < (self::DOUBLE_CLICK_MS / 1000.0)
            && $this->last_click_row === $mouse->row
            && $this->last_click_col === $mouse->col;
        $this->last_click_time = $now;
        $this->last_click_row = $mouse->row;
        $this->last_click_col = $mouse->col;

        if ($is_double) {
            // The first click already moved the selection / cursor to
            // the right row, so Enter will act on it.
            $this->dispatchEnter();
            // Reset so a third rapid click doesn't trigger another enter.
            $this->last_click_time = 0.0;
            return;
        }

        if ($hover_pane === ActivePane::Overview) {
            $this->dispatchMouseOverview($state, $body_row, $body_rows);
            return;
        }
        if ($state->mode !== ExploreMode::Sandwich) {
            $this->dispatchMouseList($body_row);
            return;
        }
        match ($state->sandwich_view) {
            SandwichView::Panes => $this->dispatchMousePanes($state, $body_row, $body_rows),
            SandwichView::TreeCallees,
            SandwichView::TreeCallers => $this->dispatchMouseTree($body_row, $body_rows),
            SandwichView::Flame => $this->dispatchMouseFlame($body_row, $screen_col, $sidebar_width, $body_rows),
        };
    }

    /**
     * Determine which logical pane the mouse pointer is hovering over.
     *
     * Returns an ActivePane value for sandwich mode or null for list mode
     * (list mode has only one pane, so no disambiguation is needed).
     */
    private function hitTestPane(
        ViewState $state,
        int $body_row,
        int $body_rows,
        int $screen_col,
        int $sidebar_width,
    ): ?ActivePane {
        if ($sidebar_width > 0 && $screen_col < $sidebar_width) {
            return ActivePane::Overview;
        }
        if ($state->mode !== ExploreMode::Sandwich) {
            return null; // list mode
        }
        if ($state->sandwich_view !== SandwichView::Panes) {
            // Flame / tree views have a single main body; treat as
            // whichever non-overview pane is active (callers/callees).
            return $state->active_pane === ActivePane::Overview
                ? ActivePane::Callees
                : $state->active_pane;
        }
        // Panes view: callers top / focus banner / callees bottom.
        $banner_rows = 3;
        $available = max(0, $body_rows - $banner_rows);
        $caller_body = (int)floor($available / 2);
        if ($body_row < $caller_body) {
            return ActivePane::Callers;
        }
        if ($body_row < $caller_body + $banner_rows) {
            return ActivePane::Focus;
        }
        return ActivePane::Callees;
    }

    /**
     * Scroll the pane that the mouse pointer is hovering over.
     */
    private function dispatchMouseScroll(ViewState $state, ?ActivePane $pane, int $delta): void
    {
        if ($pane === null) {
            // List mode.
            $this->list_selected += $delta;
            return;
        }
        // Activate the hovered pane so the scroll is visible. Without
        // this, the overview's auto-center logic would overwrite the
        // selection on the next render and the scroll would appear to
        // have no effect.
        if ($state->active_pane !== $pane && $pane !== ActivePane::Focus) {
            $this->replaceTop($state->withActivePane($pane));
        }
        if ($pane === ActivePane::Overview) {
            $this->overview_selected += $delta;
            if ($this->overview_follow) {
                $this->liveFollowOverviewFocus();
            }
            return;
        }
        // Tree and flame views have their own cursor state that is
        // separate from the per-pane callers/callees selection.
        if ($state->mode === ExploreMode::Sandwich) {
            switch ($state->sandwich_view) {
                case SandwichView::TreeCallees:
                case SandwichView::TreeCallers:
                    $this->tree_cursor_row += $delta;
                    return;
                case SandwichView::Flame:
                    $this->moveFlameCursorRow($delta > 0 ? 1 : -1);
                    return;
                case SandwichView::Panes:
                    break; // fall through to per-pane selection below
            }
        }
        match ($pane) {
            ActivePane::Callers  => $this->callers_selected += $delta,
            ActivePane::Callees  => $this->callees_selected += $delta,
            ActivePane::Overview, ActivePane::Focus => null,
        };
    }

    /**
     * Handle click / scroll on the [δN] header widget.
     * Click cycles through presets, scroll adjusts ±1 (clamped 1–20).
     */
    private function dispatchScrollDeltaWidget(
        bool $is_scroll,
        MouseEvent $mouse,
    ): void {
        if ($is_scroll) {
            $dir = $mouse->button === MouseEvent::SCROLL_UP ? -1 : 1;
            $this->scroll_delta = max(1, min(20, $this->scroll_delta + $dir));
        } else {
            // Cycle through presets.
            $idx = array_search($this->scroll_delta, self::SCROLL_DELTA_PRESETS, true);
            if ($idx === false) {
                // Current value is not a preset — snap to nearest.
                $this->scroll_delta = self::SCROLL_DELTA_PRESETS[0];
            } else {
                $next = ($idx + 1) % count(self::SCROLL_DELTA_PRESETS);
                $this->scroll_delta = self::SCROLL_DELTA_PRESETS[$next];
            }
        }
        $this->status = "scroll delta: {$this->scroll_delta}";
    }

    /**
     * Left-click on the mini-flame strip: focus on the frame at that column.
     */
    private function dispatchMouseMiniFlame(int $screen_col, int $cols): void
    {
        if ($this->mini_flame_pixel_keys === []) {
            return;
        }
        // The mini-flame spans the full terminal width. Each cell = 2 pixels.
        $pixel = $screen_col * 2;
        if ($pixel < 0 || $pixel >= count($this->mini_flame_pixel_keys)) {
            return;
        }
        $key_id = $this->mini_flame_pixel_keys[$pixel];
        if ($key_id < 0) {
            return;
        }
        $state = $this->currentState();
        if ($state->focus_id === $key_id) {
            return; // already focused on this frame
        }
        $label = Aggregator::labelFor($this->model, $key_id, $this->opts->no_line);
        $this->pushSandwichFocus($key_id, $label, $state->sandwich_view);

        // When panes or overview was active, switch to the Focus
        // banner so the mini-flame highlight tracks the newly focused
        // frame. getActiveCursorFrame() returns focus_id directly for
        // ActivePane::Focus, which is exactly what we just clicked.
        // Flame / tree views initialise their cursor onto the focus
        // bar on the next render, so they highlight correctly already.
        if (
            $state->active_pane === ActivePane::Callers
            || $state->active_pane === ActivePane::Callees
            || $state->active_pane === ActivePane::Overview
        ) {
            $this->replaceTop(
                $this->currentState()->withActivePane(ActivePane::Focus)
            );
        }
    }

    /**
     * Left-click in list mode body: row 0 is header, rows 1+ are data.
     */
    private function dispatchMouseList(int $body_row): void
    {
        if ($body_row < 1) {
            return; // clicked on header
        }
        $data_row = $body_row - 1 + $this->list_top_row;
        $total = count($this->ensureList()['rows']);
        if ($data_row < $total) {
            $this->list_selected = $data_row;
        }
    }

    /**
     * Left-click in the overview sidebar.
     */
    private function dispatchMouseOverview(ViewState $state, int $body_row, int $body_rows): void
    {
        if ($body_row < 1) {
            return; // clicked on header
        }
        $visible = max(0, $body_rows - 1);
        $data_row = $body_row - 1 + $this->overview_top_row;
        $total = count($this->ensureOverview()['rows']);
        if ($data_row >= $total) {
            return;
        }
        // Activate the overview pane and move selection.
        if ($state->active_pane !== ActivePane::Overview) {
            $this->replaceTop($state->withActivePane(ActivePane::Overview));
        }
        $this->overview_selected = $data_row;
        if ($this->overview_follow) {
            $this->liveFollowOverviewFocus();
        }
    }

    /**
     * Left-click in sandwich panes view: determine which pane was
     * clicked and set the selection within that pane.
     */
    private function dispatchMousePanes(ViewState $state, int $body_row, int $body_rows): void
    {
        $banner_rows = 3;
        $available = max(0, $body_rows - $banner_rows);
        $caller_body = (int)floor($available / 2);
        $callee_body = $available - $caller_body;

        // Callers pane: rows 0 to caller_body-1 (header + body)
        if ($body_row < $caller_body) {
            if ($body_row < 1) {
                return; // header
            }
            if ($state->active_pane !== ActivePane::Callers) {
                $this->replaceTop($state->withActivePane(ActivePane::Callers));
            }
            $data_row = $body_row - 1 + $this->callers_top_row;
            $total = count($this->ensureCallers()['rows']);
            if ($data_row < $total) {
                $this->callers_selected = $data_row;
            }
            return;
        }

        // Focus banner: rows caller_body to caller_body+2
        if ($body_row < $caller_body + $banner_rows) {
            if ($state->active_pane !== ActivePane::Focus) {
                $this->replaceTop($state->withActivePane(ActivePane::Focus));
            }
            return;
        }

        // Callees pane: rows caller_body+3 onwards (header + body)
        $callee_local = $body_row - $caller_body - $banner_rows;
        if ($callee_local < 1) {
            return; // header
        }
        if ($state->active_pane !== ActivePane::Callees) {
            $this->replaceTop($state->withActivePane(ActivePane::Callees));
        }
        $data_row = $callee_local - 1 + $this->callees_top_row;
        $total = count($this->ensureCallees()['rows']);
        if ($data_row < $total) {
            $this->callees_selected = $data_row;
        }
    }

    /**
     * Left-click in sandwich flame view: move cursor to the clicked bar.
     */
    private function dispatchMouseFlame(
        int $body_row,
        int $screen_col,
        int $sidebar_width,
        int $body_rows,
    ): void {
        $layout = $this->buildCurrentSandwichLayout();
        if ($layout === null) {
            return;
        }
        $total_visual = $layout['total_visual_rows'];
        if ($body_row < 0 || $body_row >= $total_visual) {
            return;
        }

        // Clicking the flame body should hand navigation focus to
        // the main body so that a subsequent Enter drills into the
        // clicked bar rather than acting on the overview sidebar.
        $state = $this->currentState();
        if (
            $state->active_pane === ActivePane::Overview
            || $state->active_pane === ActivePane::Focus
        ) {
            $this->replaceTop($state->withActivePane(ActivePane::Callees));
        }

        // Convert screen column to inner-body x coordinate.
        // Sidebar + separator occupy the left portion of the screen.
        $separator_width = $sidebar_width > 0 ? 1 : 0;
        $inner_x = $screen_col - $sidebar_width - $separator_width;
        if ($inner_x < 0) {
            return;
        }
        $bars = $layout['visual_rows'][$body_row] ?? [];
        if ($bars === []) {
            // Allow clicking the focus row even though it has no bars.
            if ($body_row === $layout['focus_visual_row']) {
                $this->flame_cursor_visual_row = $body_row;
                $this->flame_cursor_x = $inner_x;
            }
            return;
        }
        $hit = $this->findBarAtX($bars, $inner_x);
        if ($hit === null) {
            $hit = $this->findNearestBar($bars, $inner_x);
        }
        $this->flame_cursor_visual_row = $body_row;
        if ($hit !== null) {
            $this->flame_cursor_x = $hit['x'] + intdiv($hit['w'], 2);
        } else {
            $this->flame_cursor_x = $inner_x;
        }
    }

    /**
     * Left-click in sandwich tree view: set tree cursor to clicked row.
     */
    private function dispatchMouseTree(int $body_row, int $body_rows): void
    {
        // Tree body has a 1-line header, then data rows.
        if ($body_row < 1) {
            return;
        }
        // Hand navigation focus to the main body so Enter acts on the
        // tree cursor, not the overview sidebar.
        $state = $this->currentState();
        if (
            $state->active_pane === ActivePane::Overview
            || $state->active_pane === ActivePane::Focus
        ) {
            $this->replaceTop($state->withActivePane(ActivePane::Callees));
        }
        $this->tree_cursor_row = $body_row - 1 + $this->tree_top_row;
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

    /**
     * @param callable(string): void $commit
     */
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
            // Focus is a "cursor parking spot" with no scroll content;
            // dispatchUpDown intercepts the actual ↑↓ navigation for
            // it, so this branch is just defensive.
            ActivePane::Focus    => null,
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
            case ActivePane::Focus:
                // No scroll content on the focus banner.
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
        $this->replaceTop($state->withFocus($key_id, $label));
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
        if ($state->active_pane === ActivePane::Focus) {
            // Cursor is parked on the focus banner — drilling into
            // the current focus would just push a duplicate history
            // entry, so just no-op.
            $this->status = 'already focused';
            return;
        }
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
            case ActivePane::Focus:
                return; // unreachable, handled above
        }
        if (!isset($rows[$idx])) {
            return;
        }
        [, $key_id, $label] = $rows[$idx];
        if ($key_id < 0) {
            $this->status = 'cannot focus synthetic <root>/<leaf>';
            return;
        }
        // Inherit the current sandwich_view so focusing from the
        // overview pane while in flame / tree view stays in flame /
        // tree view, instead of dropping back to panes mode the way
        // the manual constructor used to (it defaulted sandwich_view
        // to Panes by omitting the field). pushSandwichFocus also
        // snapshots the outgoing overview cursor onto the previous
        // state and resets the per-view cursors so the new view
        // re-centres on the new focus.
        $this->pushSandwichFocus($key_id, $label, $state->sandwich_view);
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
        $this->replaceTop(new ViewState(
            mode: $state->mode,
            focus_id: $new_id,
            focus_label: $new_label,
            view_filter: $state->view_filter,
            active_pane: $state->active_pane,
        ));
    }

    private function currentState(): ViewState
    {
        // The stack is initialised with one element in the constructor
        // and never popped past that, so it's always non-empty here.
        $last = end($this->stack);
        assert($last !== false);
        return $last;
    }

    /**
     * Replace the top-of-stack ViewState. Equivalent to writing
     * `$this->stack[count($this->stack) - 1] = $state` but expressed
     * as pop + push so the list-type invariant of `$this->stack`
     * is preserved across the mutation (Psalm narrows direct
     * indexed-assignment results to a non-list array shape).
     */
    private function replaceTop(ViewState $state): void
    {
        array_pop($this->stack);
        $this->stack[] = $state;
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
     * Mirror of {@see self::shorten} that drops characters from the
     * LEFT side of $s instead of the right. Used by the right-aligned
     * flame label rendering so the rightmost part of the function
     * name (the bare method name + line number) stays visible at
     * narrow bar widths instead of being cut.
     */
    private static function shortenLeft(string $s, int $width): string
    {
        if ($width <= 0) {
            return '';
        }
        $len = mb_strlen($s);
        if ($len <= $width) {
            return $s;
        }
        if ($width <= 1) {
            return mb_substr($s, $len - $width, $width);
        }
        return '…' . mb_substr($s, $len - ($width - 1));
    }

    /**
     * Compact a "function file:line" label into "function:line" by
     * dropping the file path. Used by the right-aligned flame label
     * mode so the user keeps the line number visible (it's tiny —
     * 3-4 chars typically) without sacrificing the method name to
     * a long file path. Falls back to the raw label when the format
     * doesn't match (no_line mode, synthetic <root>/<leaf>, etc.).
     */
    private static function compactFlameLabel(string $label): string
    {
        $space_pos = strpos($label, ' ');
        if ($space_pos === false) {
            return $label;
        }
        $colon_pos = strrpos($label, ':');
        if ($colon_pos === false || $colon_pos <= $space_pos) {
            return substr($label, 0, $space_pos);
        }
        return substr($label, 0, $space_pos) . substr($label, $colon_pos);
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
