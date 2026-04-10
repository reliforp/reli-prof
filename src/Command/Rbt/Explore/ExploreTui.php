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
        // and status (1) — 6 fixed lines.
        $body_rows = $rows - 6;
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

        if ($this->help_open) {
            $buf .= $this->renderHelpOverlay($cols, $rows);
        }

        $this->term->write($buf);
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

        $this->clampSelection($this->list_selected, $this->list_top_row, count($rows_data), $body_rows - 1);

        $out = [];
        $out[] = $this->styleTableHeader(self::padOrShorten(
            sprintf('  %9s  %6s  %s', 'count', 'pct', 'frame'),
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
            $marker = $idx === $this->list_selected ? '> ' : '  ';
            $line = self::padOrShorten(
                sprintf('%s%9s  %5.1f%%  %s', $marker, number_format($count), $pct, $label),
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
        for ($i = 0; $i < $visible; $i++) {
            $idx = $top_row + $i;
            if (!isset($rows[$idx])) {
                $out[] = str_repeat(' ', $width);
                continue;
            }
            [$count, , $label] = $rows[$idx];
            $pct = ($count / $denom) * 100;
            $is_sel = $active && $idx === $selected;
            $sel_mark = $is_sel ? '> ' : '  ';
            $line = self::padOrShorten(
                sprintf('%s%9s  %5.1f%%  %s', $sel_mark, number_format($count), $pct, $label),
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
            $label_room = max(1, $width - 8);
            $line = sprintf(
                '%s %s %5.1f%%',
                $marker,
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

    private function renderFooter(ViewState $state, int $cols): string
    {
        if ($state->mode === ExploreMode::Sandwich) {
            $follow = $this->overview_follow ? '[f*]' : '[f]';
            $footer = '[Tab/⇧Tab] cycle pane  [↑↓] sel  [Enter] focus  [←/→] callers/callees  '
                . '[u] back  [s/t/O] self/total/overview  ' . $follow . ' follow  '
                . '[/] filter  [m] match  [n] no-line  [o] sidebar  [?] help  [q] quit';
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
        if ($include_overview) {
            $this->overview_cache = null;
        }
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
