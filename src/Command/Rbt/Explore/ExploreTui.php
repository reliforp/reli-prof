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
 * Holds:
 *   - the immutable {@see TraceModel} loaded once at startup
 *   - the current view stack (so `back` can pop)
 *   - the current global match filter and per-view filter
 *   - the in-progress text input state when prompting for /, m, etc.
 *
 * Each frame: aggregate → render → block on a key → dispatch.
 */
final class ExploreTui
{
    private const MIN_ROWS = 12;
    private const MIN_COLS = 60;

    private TraceModel $model;
    private Terminal $term;
    private Keymap $keymap;

    /** @var list<ViewState> stack of focuses; top is current */
    private array $stack;

    private ViewOptions $opts;

    private int $selected = 0;
    private int $top_row = 0;

    /** @var array{counts: array<int, int>, matched_samples: int}|null */
    private ?array $cached_view = null;

    /** @var list<array{int, int, string}>|null sorted view rows: [count, key_id, label] */
    private ?array $cached_rows = null;

    private string $status = '';

    /** Modal text input state (filter prompts). */
    private ?string $prompt_label = null;
    private string $prompt_buffer = '';
    /** @var callable(string): void|null */
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
            new ViewState(view: Aggregator::VIEW_SELF, focus_id: null, focus_label: null),
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

        $this->ensureAggregated();

        $rows_for_table = $rows - 8;
        if ($rows_for_table < 1) {
            $rows_for_table = 1;
        }
        $this->clampSelection($rows_for_table);

        $buf = '';
        $buf .= "\e[H\e[2J"; // home + clear

        // ----- header -----
        $state = $this->currentState();
        $buf .= $this->styleHeader(sprintf(
            'rbt:explore — %s (%s samples · %.1fs · %dus period)',
            self::shorten(basename($this->model->source_path), $cols - 4),
            number_format($this->model->sampleCount()),
            $this->model->durationSeconds(),
            $this->model->sampling_period_us,
        )) . "\n";

        $focus_label = $state->focus_label ?? '<none>';
        $hist = count($this->stack) - 1;
        $buf .= sprintf(
            "Focus: %s   History: %d back\n",
            self::shorten($focus_label, $cols - 24),
            $hist,
        );

        $view_label = $this->viewLabel($state->view);
        $line = sprintf(
            'View: %s   no-line: %s   match: %s   filter: %s',
            $view_label,
            $this->opts->no_line ? 'on' : 'off',
            $this->opts->match_re ?? '(none)',
            $state->view_filter ?? '(none)',
        );
        $buf .= self::shorten($line, $cols) . "\n";

        $buf .= str_repeat('─', $cols) . "\n";

        // ----- table header -----
        $buf .= $this->styleTableHeader(self::shorten(
            sprintf('%9s  %6s  %s', 'count', 'pct', 'frame'),
            $cols,
        )) . "\n";

        // ----- table body -----
        $rows_data = $this->visibleRows();
        $denom = max(1, $this->cached_view['matched_samples'] ?? 1);
        $rendered = 0;
        for ($i = 0; $i < $rows_for_table; $i++) {
            $idx = $this->top_row + $i;
            if (!isset($rows_data[$idx])) {
                $buf .= "\n";
                continue;
            }
            [$count, $key_id, $label] = $rows_data[$idx];
            $pct = ($count / $denom) * 100;
            $marker = $idx === $this->selected ? '> ' : '  ';
            $line = sprintf(
                '%s%9s  %5.1f%%  %s',
                $marker,
                number_format($count),
                $pct,
                $label,
            );
            $line = self::shorten($line, $cols);
            if ($idx === $this->selected) {
                $line = "\e[7m" . $line . "\e[27m";
            }
            $buf .= $line . "\n";
            $rendered++;
        }

        // ----- footer / status -----
        $footer = '[↑↓] sel  [Enter] focus  [←] callers  [→] callees  [u] back  '
            . '[s] self  [t] total  [/] filter  [m] match  [n] no-line  [?] help  [q] quit';
        $buf .= self::shorten($footer, $cols) . "\n";

        if ($this->prompt_label !== null) {
            $buf .= self::shorten("{$this->prompt_label}{$this->prompt_buffer}_", $cols);
        } else {
            $status = $this->status;
            if ($status === '') {
                $status = sprintf(
                    '%d / %d rows · matched %s / %s samples',
                    count($rows_data),
                    count($this->cached_rows ?? []),
                    number_format($this->cached_view['matched_samples'] ?? 0),
                    number_format($this->model->sampleCount()),
                );
            }
            $buf .= self::shorten($status, $cols);
        }

        if ($this->help_open) {
            $buf .= $this->renderHelpOverlay($cols, $rows);
        }

        $this->term->write($buf);
    }

    private function styleHeader(string $s): string
    {
        return "\e[1m" . $s . "\e[22m";
    }

    private function styleTableHeader(string $s): string
    {
        return "\e[2m" . $s . "\e[22m";
    }

    private function viewLabel(string $view): string
    {
        $state = $this->currentState();
        return match ($view) {
            Aggregator::VIEW_SELF    => 'self-time top',
            Aggregator::VIEW_TOTAL   => 'total-time top',
            Aggregator::VIEW_CALLERS => 'callers of ' . ($state->focus_label ?? '?'),
            Aggregator::VIEW_CALLEES => 'callees of ' . ($state->focus_label ?? '?'),
            default                  => $view,
        };
    }

    /**
     * @return list<array{int, int, string}>
     */
    private function visibleRows(): array
    {
        $rows = $this->cached_rows ?? [];
        $state = $this->currentState();
        if ($state->view_filter !== null) {
            $re = '#' . str_replace('#', '\\#', $state->view_filter) . '#';
            $rows = array_values(array_filter(
                $rows,
                static fn(array $row): bool => preg_match($re, $row[2]) === 1,
            ));
        }
        return $rows;
    }

    private function renderHelpOverlay(int $cols, int $rows): string
    {
        $lines = [
            '── help ──────────────────────────────',
            '  ↑ / k        select previous',
            '  ↓ / j        select next',
            '  PgUp / PgDn  page up / down',
            '  g / G        first / last row',
            '  Enter        focus selected row → callers view',
            '  ← / h        callers of focus',
            '  → / l        callees of focus',
            '  u / Bksp     pop focus history',
            '  s            self-time top (clears focus)',
            '  t            total-time top (clears focus)',
            '  /            filter visible rows (PCRE)',
            '  m            global sample match (PCRE)',
            '  n            toggle no-line grouping',
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

    // ---------- aggregation ----------

    private function ensureAggregated(): void
    {
        if ($this->cached_view !== null && $this->cached_rows !== null) {
            return;
        }
        $state = $this->currentState();
        $view = match ($state->view) {
            Aggregator::VIEW_SELF    => Aggregator::selfTime($this->model, $this->opts),
            Aggregator::VIEW_TOTAL   => Aggregator::totalTime($this->model, $this->opts),
            Aggregator::VIEW_CALLERS => Aggregator::callersOf(
                $this->model,
                $state->focus_id ?? -999,
                $this->opts,
            ),
            Aggregator::VIEW_CALLEES => Aggregator::calleesOf(
                $this->model,
                $state->focus_id ?? -999,
                $this->opts,
            ),
            default => ['counts' => [], 'matched_samples' => 0],
        };
        $counts = $view['counts'];
        arsort($counts);
        $rows = [];
        foreach ($counts as $key_id => $count) {
            $label = Aggregator::labelFor($this->model, $key_id, $this->opts->no_line);
            $rows[] = [$count, $key_id, $label];
        }
        $this->cached_view = $view;
        $this->cached_rows = $rows;
    }

    private function invalidate(): void
    {
        $this->cached_view = null;
        $this->cached_rows = null;
    }

    private function clampSelection(int $rows_for_table): void
    {
        $total = count($this->visibleRows());
        if ($total === 0) {
            $this->selected = 0;
            $this->top_row = 0;
            return;
        }
        if ($this->selected < 0) {
            $this->selected = 0;
        }
        if ($this->selected >= $total) {
            $this->selected = $total - 1;
        }
        if ($this->selected < $this->top_row) {
            $this->top_row = $this->selected;
        }
        if ($this->selected >= $this->top_row + $rows_for_table) {
            $this->top_row = $this->selected - $rows_for_table + 1;
        }
        if ($this->top_row < 0) {
            $this->top_row = 0;
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

        switch ($action) {
            case Keymap::ACTION_QUIT:
                $this->running = false;
                return;

            case Keymap::ACTION_HELP:
                $this->help_open = true;
                return;

            case Keymap::ACTION_UP:
                $this->selected--;
                $this->cached_rows ??= [];
                return;

            case Keymap::ACTION_DOWN:
                $this->selected++;
                return;

            case Keymap::ACTION_PAGE_UP:
                $this->selected -= 10;
                return;

            case Keymap::ACTION_PAGE_DOWN:
                $this->selected += 10;
                return;

            case Keymap::ACTION_HOME:
                $this->selected = 0;
                $this->top_row = 0;
                return;

            case Keymap::ACTION_END:
                $this->selected = max(0, count($this->visibleRows()) - 1);
                return;

            case Keymap::ACTION_FOCUS_ENTER:
                $this->focusSelected(Aggregator::VIEW_CALLERS);
                return;

            case Keymap::ACTION_CALLERS:
                $this->switchView(Aggregator::VIEW_CALLERS);
                return;

            case Keymap::ACTION_CALLEES:
                $this->switchView(Aggregator::VIEW_CALLEES);
                return;

            case Keymap::ACTION_BACK:
                $this->popFocus();
                return;

            case Keymap::ACTION_VIEW_SELF:
                $this->resetTo(Aggregator::VIEW_SELF);
                return;

            case Keymap::ACTION_VIEW_TOTAL:
                $this->resetTo(Aggregator::VIEW_TOTAL);
                return;

            case Keymap::ACTION_FILTER_VIEW:
                $this->openPrompt('view filter (PCRE, empty = clear): ', function (string $value): void {
                    $state = $this->currentState();
                    $value = $value === '' ? null : $value;
                    if ($value !== null && @preg_match('#' . str_replace('#', '\\#', $value) . '#', '') === false) {
                        $this->status = "invalid view filter: {$value}";
                        return;
                    }
                    $this->stack[count($this->stack) - 1] = $state->withViewFilter($value);
                    $this->selected = 0;
                    $this->top_row = 0;
                    $this->status = $value === null ? 'view filter cleared' : "view filter: {$value}";
                });
                return;

            case Keymap::ACTION_FILTER_MATCH:
                $this->openPrompt('global match (PCRE, empty = clear): ', function (string $value): void {
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
                    $this->selected = 0;
                    $this->top_row = 0;
                    $this->invalidate();
                });
                return;

            case Keymap::ACTION_NO_LINE:
                $this->opts = $this->opts->withNoLine(!$this->opts->no_line);
                $this->refocusForNoLineToggle();
                $this->invalidate();
                $this->selected = 0;
                $this->top_row = 0;
                $this->status = 'no-line: ' . ($this->opts->no_line ? 'on' : 'off');
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

    private function focusSelected(string $next_view): void
    {
        $rows = $this->visibleRows();
        if (!isset($rows[$this->selected])) {
            return;
        }
        [, $key_id, $label] = $rows[$this->selected];
        if ($key_id < 0) {
            $this->status = 'cannot focus synthetic <root>/<leaf>';
            return;
        }
        $this->stack[] = new ViewState(view: $next_view, focus_id: $key_id, focus_label: $label);
        $this->selected = 0;
        $this->top_row = 0;
        $this->invalidate();
    }

    private function switchView(string $view): void
    {
        $state = $this->currentState();
        if ($state->focus_id === null) {
            // Need a focus to look at callers/callees — focus the selected row first.
            $this->focusSelected($view);
            return;
        }
        $this->stack[count($this->stack) - 1] = $state->withView($view);
        $this->selected = 0;
        $this->top_row = 0;
        $this->invalidate();
    }

    private function popFocus(): void
    {
        if (count($this->stack) <= 1) {
            $this->status = 'no history';
            return;
        }
        array_pop($this->stack);
        $this->selected = 0;
        $this->top_row = 0;
        $this->invalidate();
    }

    private function resetTo(string $view): void
    {
        $this->stack = [new ViewState(view: $view, focus_id: null, focus_label: null)];
        $this->selected = 0;
        $this->top_row = 0;
        $this->invalidate();
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
            // line → no-line: map focus_id via no_line_map.
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
            view: $state->view,
            focus_id: $new_id,
            focus_label: $new_label,
            view_filter: $state->view_filter,
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
}
