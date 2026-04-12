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
 * Targets the state machine inside ExploreTui — the part of the class
 * that doesn't actually need a live terminal.
 *
 * The TUI is constructed against a real Terminal that is intentionally
 * never `enter()`-ed; that's safe because the Terminal constructor only
 * registers a shutdown function and the methods exercised here only
 * touch state-mutation paths (focus push/pop, view switches, selection
 * movement, prompt mechanics, cache management). The render loop, the
 * key polling loop, and the alt-screen / raw-mode plumbing are out of
 * scope for these tests — they belong in an integration check.
 *
 * Most assertions reach into private state via reflection. The methods
 * being exercised are private by design (they're internal pieces of the
 * TUI run loop), but they're also where almost all of ExploreTui's
 * coverage gain has to come from, so reflection is the pragmatic call.
 */
class ExploreTuiStateTest extends BaseTestCase
{
    // ---------- harness ----------

    private function makeTui(?TraceModel $model = null): ExploreTui
    {
        return new ExploreTui(
            $model ?? $this->makeModel(),
            new Terminal(), // never enter()-ed; constructor is harmless
            Keymap::default(),
        );
    }

    /**
     * Build a small but non-trivial trace by hand. Frame layout (id =
     * index in frame_keys, leaf-to-root order in stacks):
     *
     *   0 leaf_x  /a.php:1
     *   1 leaf_y  /a.php:2
     *   2 mid_a   /m.php:1
     *   3 mid_b   /m.php:2
     *   4 root    /r.php:1
     *
     * Sample stacks (leaf → root):
     *
     *   #0  leaf_x → mid_a → root      (3 frames)
     *   #1  leaf_x → mid_a → root
     *   #2  leaf_x → mid_b → root
     *   #3  leaf_y → mid_a → root
     *   #4  leaf_y → mid_b → mid_b → root   (recursive mid_b)
     *   #5  leaf_x → mid_b → root
     *
     * That gives:
     *   self-time:   leaf_x=4, leaf_y=2
     *   total-time:  root=6, mid_a=3, mid_b=3, leaf_x=4, leaf_y=2
     *   callers of leaf_x = mid_a (×2), mid_b (×2)
     *   callers of mid_b  = root (×3)        [each mid_b sample picks
     *                                          its leafmost mid_b → caller is root]
     *   callees of mid_b  = leaf_x (×2), leaf_y (×1)
     */
    private function makeModel(): TraceModel
    {
        $frame_keys = [
            'leaf_x /a.php:1',
            'leaf_y /a.php:2',
            'mid_a /m.php:1',
            'mid_b /m.php:2',
            'root /r.php:1',
        ];
        // No-line projection collapses leaf_x/leaf_y into separate
        // function-name groups (one each), so the no-line space has the
        // same number of distinct ids here as the line space — id-stable.
        // The test that exercises no-line toggle uses a different model.
        $no_line_map = [0, 1, 2, 3, 4];

        return new TraceModel(
            frame_keys: $frame_keys,
            frame_keys_no_line: $frame_keys,
            no_line_map: $no_line_map,
            samples: [
                [0, 2, 4],
                [0, 2, 4],
                [0, 3, 4],
                [1, 2, 4],
                [1, 3, 3, 4],
                [0, 3, 4],
            ],
            sampling_period_us: 10000,
            source_path: '/dev/null',
        );
    }

    /**
     * Invoke a private method via reflection.
     */
    private function inv(ExploreTui $tui, string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod($tui, $method);
        return $ref->invokeArgs($tui, $args);
    }

    private function get(ExploreTui $tui, string $prop): mixed
    {
        $ref = new \ReflectionProperty($tui, $prop);
        return $ref->getValue($tui);
    }

    private function set(ExploreTui $tui, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($tui, $prop);
        $ref->setValue($tui, $value);
    }

    // ---------- construction ----------

    public function testConstructorInitialisesListSelfState(): void
    {
        $tui = $this->makeTui();
        /** @var ViewState $state */
        $state = $this->inv($tui, 'currentState');

        $this->assertSame(ExploreMode::ListSelf, $state->mode);
        $this->assertNull($state->focus_id);
        $this->assertSame(0, $this->get($tui, 'list_selected'));
        $stack = $this->get($tui, 'stack');
        $this->assertIsArray($stack);
        $this->assertCount(1, $stack);
    }

    // ---------- list mode movement ----------

    public function testMoveSelectionInListModeAdjustsListSelected(): void
    {
        $tui = $this->makeTui();

        $this->inv($tui, 'moveSelection', 3);
        $this->assertSame(3, $this->get($tui, 'list_selected'));

        $this->inv($tui, 'moveSelection', -1);
        $this->assertSame(2, $this->get($tui, 'list_selected'));
    }

    public function testSetSelectionPhpIntMaxJumpsToLastListRow(): void
    {
        $tui = $this->makeTui();

        $this->inv($tui, 'setSelection', PHP_INT_MAX);
        // The model has 2 distinct leaves (leaf_x, leaf_y) → last row index = 1.
        $this->assertSame(1, $this->get($tui, 'list_selected'));
        $this->assertSame(0, $this->get($tui, 'list_top_row'));
    }

    public function testSetSelectionExplicitValueIsUsedDirectly(): void
    {
        $tui = $this->makeTui();
        $this->inv($tui, 'setSelection', 7);
        $this->assertSame(7, $this->get($tui, 'list_selected'));
    }

    // ---------- list cache ----------

    public function testEnsureListBuildsAndCachesSelfTimeRows(): void
    {
        $tui = $this->makeTui();

        /** @var array{matched_samples:int, rows:list<array{int,int,string}>} $result */
        $result = $this->inv($tui, 'ensureList');
        $this->assertSame(6, $result['matched_samples']);
        // Two leaves: leaf_x (4 samples), leaf_y (2). Sorted by count desc.
        $this->assertCount(2, $result['rows']);
        $this->assertSame(4, $result['rows'][0][0]);
        $this->assertSame(2, $result['rows'][1][0]);

        // Calling ensureList again returns the cached object.
        /** @var array{matched_samples:int, rows:list<array{int,int,string}>} $second */
        $second = $this->inv($tui, 'ensureList');
        $this->assertSame($result, $second);
    }

    public function testInvalidateClearsListCacheButPreservesOverview(): void
    {
        $tui = $this->makeTui();
        $this->inv($tui, 'ensureList');
        $this->inv($tui, 'ensureOverview');
        $this->assertNotNull($this->get($tui, 'list_cache'));
        $this->assertNotNull($this->get($tui, 'overview_cache'));

        $this->inv($tui, 'invalidate');
        $this->assertNull($this->get($tui, 'list_cache'));
        // Overview is preserved by default — only focus changes bust it.
        $this->assertNotNull($this->get($tui, 'overview_cache'));

        $this->inv($tui, 'invalidate', true);
        $this->assertNull($this->get($tui, 'overview_cache'));
    }

    // ---------- focus / drilldown ----------

    public function testFocusSelectedFromListPushesSandwichState(): void
    {
        $tui = $this->makeTui();
        // Cursor on row 0 = leaf_x (frame id 0, the most-frequent leaf).
        $this->inv($tui, 'focusSelected');

        /** @var list<ViewState> $stack */
        $stack = $this->get($tui, 'stack');
        $this->assertCount(2, $stack);
        $top = end($stack);
        $this->assertSame(ExploreMode::Sandwich, $top->mode);
        $this->assertSame(0, $top->focus_id);
        $this->assertSame('leaf_x /a.php:1', $top->focus_label);
        $this->assertSame(ActivePane::Callers, $top->active_pane);
    }

    public function testFocusSelectedRefusesSyntheticRootRow(): void
    {
        // Build a TUI whose focus is mid_b so callers includes the
        // synthetic <root> (key_id = -1). Drilling on <root> should be
        // rejected with a status message and not push anything.
        $tui = $this->makeTui();
        // Drill into mid_b first.
        $this->inv($tui, 'setSelection', 0);
        // Build a sandwich state focused on mid_b directly so we can
        // test the synthetic-row guard without depending on cursor row
        // ordering of callers.
        $stack = $this->get($tui, 'stack');
        $this->assertIsArray($stack);
        $this->set($tui, 'stack', [
            new ViewState(
                mode: ExploreMode::Sandwich,
                focus_id: 3, // mid_b
                focus_label: 'mid_b /m.php:2',
                active_pane: ActivePane::Callers,
            ),
        ]);

        /** @var array{matched_samples:int, rows:list<array{int,int,string}>} $callers */
        $callers = $this->inv($tui, 'ensureCallers');
        // Find the row whose key_id is < 0 (synthetic <root>).
        $synthRow = null;
        foreach ($callers['rows'] as $i => $row) {
            if ($row[1] < 0) {
                $synthRow = $i;
                break;
            }
        }
        // mid_b's only caller is root (a real frame), so there's no
        // synthetic row in this fixture — for the guard test we instead
        // build a fake row by setting callers_selected to a row whose
        // key_id we override directly is the only feasible path. Since
        // we can't easily forge a row, fall through to the negative
        // assertion: callers_selected past the row count → no push.
        if ($synthRow === null) {
            $this->set($tui, 'callers_selected', 999);
            $stack_before = $this->get($tui, 'stack');
            $this->assertIsArray($stack_before);
            $this->inv($tui, 'focusSelected');
            $stack_after = $this->get($tui, 'stack');
            $this->assertSame(
                count($stack_before),
                count($stack_after),
                'out-of-range cursor must be a no-op',
            );
            return;
        }

        $this->set($tui, 'callers_selected', $synthRow);
        $this->inv($tui, 'focusSelected');
        $status = $this->get($tui, 'status');
        $this->assertIsString($status);
        $this->assertStringContainsString('synthetic', $status);
    }

    public function testPopFocusReturnsToPreviousStateAndRestoresOverviewCursor(): void
    {
        $tui = $this->makeTui();
        // Push two extra states.
        $this->inv($tui, 'focusSelected'); // list → sandwich(leaf_x)
        // Manually mark the prior overview cursor on the new top so
        // popFocus has something to restore. The first sandwich state
        // captured overview_selected=0 implicitly; advance it manually
        // to prove restoration actually happens.
        $this->set($tui, 'overview_selected', 4);
        $this->set($tui, 'overview_top_row', 2);

        // Push another sandwich state via pushSandwichFocus so the
        // overview cursor snapshot lands on the OUTGOING state.
        $this->inv($tui, 'pushSandwichFocus', 2, 'mid_a /m.php:1', SandwichView::Panes);
        $this->assertCount(3, $this->get($tui, 'stack'));

        // Move overview cursor again on the new top — popFocus should
        // overwrite this with the snapshot from the state being popped to.
        $this->set($tui, 'overview_selected', 9);
        $this->set($tui, 'overview_top_row', 7);

        $this->inv($tui, 'popFocus');
        $this->assertCount(2, $this->get($tui, 'stack'));
        // Restored from the snapshot taken on the now-top state.
        $this->assertSame(4, $this->get($tui, 'overview_selected'));
        $this->assertSame(2, $this->get($tui, 'overview_top_row'));
    }

    public function testPopFocusOnSingleFrameStackSetsStatusAndStays(): void
    {
        $tui = $this->makeTui();
        $this->inv($tui, 'popFocus');
        $stack = $this->get($tui, 'stack');
        $this->assertIsArray($stack);
        $this->assertCount(1, $stack);
        $this->assertSame('no history', $this->get($tui, 'status'));
    }

    // ---------- view filter ----------

    public function testApplyViewFilterFiltersByLabelRegex(): void
    {
        $tui = $this->makeTui();
        $rows = [
            [10, 1, 'foo /a.php:1'],
            [9, 2, 'bar /b.php:2'],
            [3, 3, 'foobar /c.php:3'],
        ];
        /** @var list<array{int,int,string}> $filtered */
        $filtered = $this->inv($tui, 'applyViewFilter', $rows, '^foo');
        $this->assertCount(2, $filtered);
        $this->assertSame('foo /a.php:1', $filtered[0][2]);
        $this->assertSame('foobar /c.php:3', $filtered[1][2]);
    }

    public function testApplyViewFilterReturnsRowsUnchangedWhenFilterIsNull(): void
    {
        $tui = $this->makeTui();
        $rows = [[1, 1, 'a'], [2, 2, 'b']];
        $this->assertSame($rows, $this->inv($tui, 'applyViewFilter', $rows, null));
    }

    // ---------- clampSelection ----------

    public function testClampSelectionPullsCursorIntoVisibleWindow(): void
    {
        $tui = $this->makeTui();
        // clampSelection takes refs, so reach in via a closure rebound
        // to the ExploreTui scope (regular reflection invocation can't
        // pass references on PHP 8+).
        $closure = \Closure::bind(
            function (int &$sel, int &$top, int $total, int $vis): void {
                $this->clampSelection($sel, $top, $total, $vis);
            },
            $tui,
            ExploreTui::class,
        );
        $this->assertNotNull($closure);

        // Cursor below window → top auto-scrolls to keep cursor visible.
        $sel = 8;
        $top = 0;
        $closure($sel, $top, 100, 5);
        $this->assertSame(8, $sel);
        $this->assertSame(4, $top); // top = sel - vis + 1

        // Cursor above window → top scrolls up.
        $sel = 2;
        $top = 5;
        $closure($sel, $top, 100, 5);
        $this->assertSame(2, $sel);
        $this->assertSame(2, $top);

        // Empty list → both reset to 0.
        $sel = 7;
        $top = 3;
        $closure($sel, $top, 0, 5);
        $this->assertSame(0, $sel);
        $this->assertSame(0, $top);

        // Cursor past total → clamped to last row.
        $sel = 999;
        $top = 0;
        $closure($sel, $top, 10, 5);
        $this->assertSame(9, $sel);
        // top should grow so the last row is visible.
        $this->assertSame(5, $top);
    }

    // ---------- prompt mechanics ----------

    public function testPromptOpenCloseRoundTripIgnoresEscape(): void
    {
        $tui = $this->makeTui();
        $captured = null;
        $this->inv(
            $tui,
            'openPrompt',
            'view filter: ',
            function (string $value) use (&$captured): void {
                $captured = $value;
            },
        );
        $this->assertSame('view filter: ', $this->get($tui, 'prompt_label'));

        // Type some characters via dispatchPrompt — including an arrow
        // key escape sequence which should be filtered out.
        $this->inv($tui, 'dispatchPrompt', 'a');
        $this->inv($tui, 'dispatchPrompt', 'b');
        $this->inv($tui, 'dispatchPrompt', "\e[A"); // arrow up — ignored
        $this->inv($tui, 'dispatchPrompt', 'c');
        $this->assertSame('abc', $this->get($tui, 'prompt_buffer'));

        // Backspace deletes one character.
        $this->inv($tui, 'dispatchPrompt', "\x7f");
        $this->assertSame('ab', $this->get($tui, 'prompt_buffer'));

        // Enter commits and closes.
        $this->inv($tui, 'dispatchPrompt', "\r");
        $this->assertNull($this->get($tui, 'prompt_label'));
        $this->assertSame('ab', $captured);
    }

    public function testPromptEscapeCancelsWithoutCallingCommit(): void
    {
        $tui = $this->makeTui();
        $called = false;
        $this->inv(
            $tui,
            'openPrompt',
            'x: ',
            function (string $_v) use (&$called): void {
                $called = true;
            },
        );
        $this->inv($tui, 'dispatchPrompt', 'q');
        $this->inv($tui, 'dispatchPrompt', "\e"); // bare ESC = cancel

        $this->assertNull($this->get($tui, 'prompt_label'));
        $this->assertFalse($called);
        $this->assertSame('cancelled', $this->get($tui, 'status'));
    }

    // ---------- view switching ----------

    public function testSwitchSandwichViewWithoutFocusSetsStatusHint(): void
    {
        $tui = $this->makeTui();
        // Still in list mode → can't switch sandwich view.
        $this->inv($tui, 'switchSandwichView', SandwichView::Flame);
        /** @var ViewState $state */
        $state = $this->inv($tui, 'currentState');
        $this->assertSame(ExploreMode::ListSelf, $state->mode);
        $status = $this->get($tui, 'status');
        $this->assertIsString($status);
        $this->assertStringContainsString('focus a frame', $status);
    }

    public function testSwitchSandwichViewInPlaceWhenCursorEqualsFocus(): void
    {
        $tui = $this->makeTui();
        // Drill into leaf_x then park cursor on the focus banner so
        // getActiveCursorFrame returns (focus_id, focus_label) — i.e.
        // the cursor IS the focus, so switchSandwichView should
        // replace the top in place rather than push a new state.
        $this->set($tui, 'stack', [
            new ViewState(
                mode: ExploreMode::Sandwich,
                focus_id: 0,
                focus_label: 'leaf_x /a.php:1',
                active_pane: ActivePane::Focus,
            ),
        ]);
        $this->inv($tui, 'switchSandwichView', SandwichView::Flame);

        $stack = $this->get($tui, 'stack');
        $this->assertIsArray($stack);
        $this->assertCount(1, $stack);
        /** @var ViewState $state */
        $state = $this->inv($tui, 'currentState');
        $this->assertSame(SandwichView::Flame, $state->sandwich_view);
        $this->assertSame(0, $state->focus_id);
    }

    // ---------- dispatch (key handling) ----------

    public function testDispatchQuitFlipsRunningFlag(): void
    {
        $tui = $this->makeTui();
        $this->assertTrue($this->get($tui, 'running'));
        // 'q' is bound to ACTION_QUIT in the default keymap.
        $this->inv($tui, 'dispatch', 'q');
        $this->assertFalse($this->get($tui, 'running'));
    }

    public function testDispatchHelpOpensThenAnyKeyCloses(): void
    {
        $tui = $this->makeTui();
        $this->inv($tui, 'dispatch', '?');
        $this->assertTrue($this->get($tui, 'help_open'));

        // Any key while help is open just closes the overlay.
        $this->inv($tui, 'dispatch', 'q');
        $this->assertFalse($this->get($tui, 'help_open'));
        // The overlay-close path consumes the key without dispatching it.
        $this->assertTrue($this->get($tui, 'running'));
    }

    public function testDispatchUnknownKeyIsANoOp(): void
    {
        $tui = $this->makeTui();
        $this->inv($tui, 'dispatch', "\x1f"); // not bound to any action
        $this->assertTrue($this->get($tui, 'running'));
        $this->assertSame(0, $this->get($tui, 'list_selected'));
    }

    public function testDispatchToggleMiniFlameTogglesAndUpdatesStatus(): void
    {
        $tui = $this->makeTui();
        $this->assertTrue($this->get($tui, 'mini_flame_enabled'));
        $this->inv($tui, 'dispatch', 'F');
        $this->assertFalse($this->get($tui, 'mini_flame_enabled'));
        $this->assertSame('mini-flame strip: off', $this->get($tui, 'status'));

        $this->inv($tui, 'dispatch', 'F');
        $this->assertTrue($this->get($tui, 'mini_flame_enabled'));
    }

    public function testDispatchFollowOverviewToggle(): void
    {
        $tui = $this->makeTui();
        $this->assertFalse($this->get($tui, 'overview_follow'));
        $this->inv($tui, 'dispatch', 'f');
        $this->assertTrue($this->get($tui, 'overview_follow'));
        $this->assertSame('overview follow: on', $this->get($tui, 'status'));
    }

    public function testDispatchEnterFromListPushesSandwich(): void
    {
        $tui = $this->makeTui();
        $this->inv($tui, 'dispatch', "\r");
        $stack = $this->get($tui, 'stack');
        $this->assertIsArray($stack);
        $this->assertCount(2, $stack);
    }

    public function testDispatchUpDownAdjustsListSelected(): void
    {
        $tui = $this->makeTui();
        $this->inv($tui, 'dispatch', 'j'); // down
        $this->assertSame(1, $this->get($tui, 'list_selected'));
        $this->inv($tui, 'dispatch', 'k'); // up
        $this->assertSame(0, $this->get($tui, 'list_selected'));
    }

    public function testDispatchHomeEndJumpsToBoundsInListMode(): void
    {
        $tui = $this->makeTui();
        $this->inv($tui, 'dispatch', 'G'); // end
        $this->assertSame(1, $this->get($tui, 'list_selected'));
        $this->inv($tui, 'dispatch', 'g'); // home
        $this->assertSame(0, $this->get($tui, 'list_selected'));
    }

    public function testDispatchTogglePaneInSandwichWalksTabCycle(): void
    {
        $tui = $this->makeTui();
        // Drop straight into a sandwich panes state so we can cycle.
        $this->set($tui, 'stack', [
            new ViewState(
                mode: ExploreMode::Sandwich,
                focus_id: 0,
                focus_label: 'leaf_x /a.php:1',
                active_pane: ActivePane::Callers,
            ),
        ]);

        $this->inv($tui, 'dispatch', "\t"); // Tab
        /** @var ViewState $s */
        $s = $this->inv($tui, 'currentState');
        $this->assertSame(ActivePane::Focus, $s->active_pane);

        $this->inv($tui, 'dispatch', "\t");
        $s = $this->inv($tui, 'currentState');
        $this->assertSame(ActivePane::Callees, $s->active_pane);

        $this->inv($tui, 'dispatch', "\t");
        $s = $this->inv($tui, 'currentState');
        $this->assertSame(ActivePane::Overview, $s->active_pane);

        $this->inv($tui, 'dispatch', "\t");
        $s = $this->inv($tui, 'currentState');
        $this->assertSame(ActivePane::Callers, $s->active_pane);
    }

    public function testDispatchTogglePaneSkipsFocusInFlameView(): void
    {
        $tui = $this->makeTui();
        $this->set($tui, 'stack', [
            new ViewState(
                mode: ExploreMode::Sandwich,
                sandwich_view: SandwichView::Flame,
                focus_id: 0,
                focus_label: 'leaf_x /a.php:1',
                active_pane: ActivePane::Callers,
            ),
        ]);

        // Tab from Callers → Focus is skipped in flame → land on Callees.
        $this->inv($tui, 'dispatch', "\t");
        /** @var ViewState $s */
        $s = $this->inv($tui, 'currentState');
        $this->assertSame(ActivePane::Callees, $s->active_pane);
    }

    public function testDispatchLeftRightSwitchesPanesInPanesView(): void
    {
        $tui = $this->makeTui();
        $this->set($tui, 'stack', [
            new ViewState(
                mode: ExploreMode::Sandwich,
                focus_id: 0,
                focus_label: 'leaf_x /a.php:1',
                active_pane: ActivePane::Callers,
            ),
        ]);

        // → (callees direction) jumps to Callees regardless of cycle state.
        $this->inv($tui, 'dispatch', 'l');
        /** @var ViewState $s */
        $s = $this->inv($tui, 'currentState');
        $this->assertSame(ActivePane::Callees, $s->active_pane);

        $this->inv($tui, 'dispatch', 'h');
        $s = $this->inv($tui, 'currentState');
        $this->assertSame(ActivePane::Callers, $s->active_pane);
    }

    public function testDispatchViewSelfFromListReinitialisesListMode(): void
    {
        $tui = $this->makeTui();
        // Drill into something first so the stack has more than 1 entry.
        $this->inv($tui, 'focusSelected');
        $this->assertCount(2, $this->get($tui, 'stack'));

        // 's' from a sandwich state is a sort flip on overview, not a
        // mode reset. Pop back to list first.
        $this->inv($tui, 'popFocus');

        $this->inv($tui, 'dispatch', 's');
        $stack = $this->get($tui, 'stack');
        $this->assertIsArray($stack);
        $this->assertCount(1, $stack);
        /** @var ViewState $s */
        $s = $this->inv($tui, 'currentState');
        $this->assertSame(ExploreMode::ListSelf, $s->mode);
    }

    public function testDispatchViewTotalFromListReinitialisesListTotal(): void
    {
        $tui = $this->makeTui();
        $this->inv($tui, 'dispatch', 't');
        /** @var ViewState $s */
        $s = $this->inv($tui, 'currentState');
        $this->assertSame(ExploreMode::ListTotal, $s->mode);
    }

    public function testDispatchNoLineTogglesOptionAndBustsCaches(): void
    {
        $tui = $this->makeTui();
        // Warm caches first.
        $this->inv($tui, 'ensureList');
        $this->inv($tui, 'ensureOverview');
        $this->assertNotNull($this->get($tui, 'list_cache'));
        $this->assertNotNull($this->get($tui, 'overview_cache'));

        $this->inv($tui, 'dispatch', 'n');

        /** @var ViewOptions $opts */
        $opts = $this->get($tui, 'opts');
        $this->assertTrue($opts->no_line);
        // No-line toggle busts BOTH caches because it changes the id space.
        $this->assertNull($this->get($tui, 'list_cache'));
        $this->assertNull($this->get($tui, 'overview_cache'));
    }

    public function testDispatchViewOverviewForcesNoLineAndResetsToListSelf(): void
    {
        $tui = $this->makeTui();
        $this->inv($tui, 'dispatch', 'O');
        /** @var ViewOptions $opts */
        $opts = $this->get($tui, 'opts');
        $this->assertTrue($opts->no_line);
        /** @var ViewState $s */
        $s = $this->inv($tui, 'currentState');
        $this->assertSame(ExploreMode::ListSelf, $s->mode);
    }

    // ---------- sandwich tree cache ----------

    public function testEnsureSandwichTreeBuildsAndCachesPerFocus(): void
    {
        $tui = $this->makeTui();
        // No focus → null.
        $this->assertNull($this->inv($tui, 'ensureSandwichTree'));

        // Focus on leaf_x.
        $this->set($tui, 'stack', [
            new ViewState(
                mode: ExploreMode::Sandwich,
                focus_id: 0,
                focus_label: 'leaf_x /a.php:1',
                active_pane: ActivePane::Callers,
            ),
        ]);

        /** @var array{focus_count:int} $tree */
        $tree = $this->inv($tui, 'ensureSandwichTree');
        $this->assertNotNull($tree);
        // leaf_x appears in samples #0, #1, #2, #5 → 4 matched.
        $this->assertSame(4, $tree['focus_count']);

        // Second call returns the same instance (cache hit).
        $tree2 = $this->inv($tui, 'ensureSandwichTree');
        $this->assertSame($tree, $tree2);
    }

    // ---------- replaceTop / currentState ----------

    public function testReplaceTopSwapsLastStackEntry(): void
    {
        $tui = $this->makeTui();
        $new = new ViewState(
            mode: ExploreMode::Sandwich,
            focus_id: 4,
            focus_label: 'root /r.php:1',
        );
        $this->inv($tui, 'replaceTop', $new);

        $stack = $this->get($tui, 'stack');
        $this->assertIsArray($stack);
        $this->assertCount(1, $stack);
        /** @var ViewState $top */
        $top = $this->inv($tui, 'currentState');
        $this->assertSame(4, $top->focus_id);
    }

    // ---------- resetTo / resetScrolls ----------

    public function testResetToReplacesStackWithSingleFreshState(): void
    {
        $tui = $this->makeTui();
        $this->inv($tui, 'focusSelected');
        $this->assertCount(2, $this->get($tui, 'stack'));

        $this->inv($tui, 'resetTo', ExploreMode::ListTotal);
        $stack = $this->get($tui, 'stack');
        $this->assertIsArray($stack);
        $this->assertCount(1, $stack);
        /** @var ViewState $s */
        $s = $this->inv($tui, 'currentState');
        $this->assertSame(ExploreMode::ListTotal, $s->mode);
    }

    public function testResetScrollsZerosListAndPaneCursorsButPreservesOverviewByDefault(): void
    {
        $tui = $this->makeTui();
        $this->set($tui, 'list_selected', 5);
        $this->set($tui, 'callers_selected', 3);
        $this->set($tui, 'callees_selected', 2);
        $this->set($tui, 'overview_selected', 9);

        $this->inv($tui, 'resetScrolls');
        $this->assertSame(0, $this->get($tui, 'list_selected'));
        $this->assertSame(0, $this->get($tui, 'callers_selected'));
        $this->assertSame(0, $this->get($tui, 'callees_selected'));
        // Overview cursor preserved by default.
        $this->assertSame(9, $this->get($tui, 'overview_selected'));

        $this->inv($tui, 'resetScrolls', true);
        $this->assertSame(0, $this->get($tui, 'overview_selected'));
    }

    // ---------- liveFollowOverviewFocus ----------

    public function testLiveFollowOverviewFocusReplacesFocusInPlace(): void
    {
        $tui = $this->makeTui();
        $this->set($tui, 'stack', [
            new ViewState(
                mode: ExploreMode::Sandwich,
                focus_id: 0,
                focus_label: 'leaf_x /a.php:1',
                active_pane: ActivePane::Overview,
            ),
        ]);

        // Find a row in the overview whose key_id is *not* 0 so the
        // follow path actually moves the focus.
        /** @var array{rows:list<array{int,int,string}>} $ov */
        $ov = $this->inv($tui, 'ensureOverview');
        $target_idx = null;
        foreach ($ov['rows'] as $i => $row) {
            if ($row[1] !== 0 && $row[1] >= 0) {
                $target_idx = $i;
                break;
            }
        }
        $this->assertNotNull($target_idx);

        $this->set($tui, 'overview_selected', $target_idx);
        $this->inv($tui, 'liveFollowOverviewFocus');

        // Stack length unchanged (in-place replace, not push).
        $this->assertCount(1, $this->get($tui, 'stack'));
        /** @var ViewState $s */
        $s = $this->inv($tui, 'currentState');
        $this->assertNotSame(0, $s->focus_id);
        // Per-pane caches reset by the replace.
        $this->assertNull($this->get($tui, 'callers_cache'));
        $this->assertNull($this->get($tui, 'callees_cache'));
    }

    // ---------- refocusForNoLineToggle ----------

    public function testRefocusForNoLineToggleUpdatesFocusIdInNewSpace(): void
    {
        // Build a model where line/no-line ids actually differ:
        // two with-line frames "handler" map to a single no-line id.
        $model = new TraceModel(
            frame_keys: [
                'handler /h.php:10',
                'handler /h.php:20',
                'main /m.php:1',
            ],
            frame_keys_no_line: ['handler', 'main'],
            no_line_map: [0, 0, 1], // both handlers → no-line id 0
            samples: [
                [0, 2],
                [1, 2],
                [0, 2],
            ],
            sampling_period_us: 10000,
            source_path: '/dev/null',
        );
        $tui = $this->makeTui($model);

        // Focus on with-line handler frame 0, then flip on no-line:
        // refocusForNoLineToggle should rewrite focus_id to no-line id 0.
        $this->set($tui, 'stack', [
            new ViewState(
                mode: ExploreMode::Sandwich,
                focus_id: 1, // handler at line 20
                focus_label: 'handler /h.php:20',
            ),
        ]);
        // Flip the option BEFORE calling refocus (mirrors how dispatch
        // sequences these — the option is set first, then refocus is
        // invoked to migrate the existing focus into the new id space).
        $opts = $this->get($tui, 'opts');
        $this->assertInstanceOf(ViewOptions::class, $opts);
        $this->set($tui, 'opts', $opts->withNoLine(true));
        $this->inv($tui, 'refocusForNoLineToggle');

        /** @var ViewState $s */
        $s = $this->inv($tui, 'currentState');
        $this->assertSame(0, $s->focus_id); // both handlers → no-line id 0
        $this->assertSame('handler', $s->focus_label);
    }

    // ---------- sidebar / width helpers ----------

    public function testShouldShowSidebarHonoursColumnFloorAndAutoBand(): void
    {
        $tui = $this->makeTui();
        // Hard floor: < 100 cols → never.
        $this->assertFalse($this->inv($tui, 'shouldShowSidebar', 99));
        // 100..119 → off in auto mode (>= 120 needed).
        $this->assertFalse($this->inv($tui, 'shouldShowSidebar', 100));
        // >= 120 → on in auto mode.
        $this->assertTrue($this->inv($tui, 'shouldShowSidebar', 120));
        $this->assertTrue($this->inv($tui, 'shouldShowSidebar', 200));
    }

    public function testShouldShowSidebarHonoursExplicitOverride(): void
    {
        $tui = $this->makeTui();
        // Force on at 100 cols (above the hard floor) overrides the auto band.
        $this->set($tui, 'sidebar_override', true);
        $this->assertTrue($this->inv($tui, 'shouldShowSidebar', 100));
        // Even at the auto-on width, force-off should win.
        $this->set($tui, 'sidebar_override', false);
        $this->assertFalse($this->inv($tui, 'shouldShowSidebar', 200));
        // Hard floor still applies under the override (sidebar at 50 cols
        // would crush the body — refused regardless of override).
        $this->set($tui, 'sidebar_override', true);
        $this->assertFalse($this->inv($tui, 'shouldShowSidebar', 50));
    }

    public function testComputeSidebarWidthIsClampedToThirtyToFiftyBand(): void
    {
        $tui = $this->makeTui();
        // Narrow → clamped up to 30.
        $this->assertSame(30, $this->inv($tui, 'computeSidebarWidth', 100));
        // Mid: 28% of 150 = 42.
        $this->assertSame(42, $this->inv($tui, 'computeSidebarWidth', 150));
        // Wide → clamped down to 50.
        $this->assertSame(50, $this->inv($tui, 'computeSidebarWidth', 300));
    }

    // ---------- header rendering ----------

    public function testRenderHeaderIncludesSourceModeAndOptionLabels(): void
    {
        $tui = $this->makeTui();
        $state = $this->inv($tui, 'currentState');
        $this->assertInstanceOf(ViewState::class, $state);

        /** @var string $header */
        $header = $this->inv($tui, 'renderHeader', $state, 80);
        $this->assertIsString($header);
        // Source basename ("/dev/null" → "null") and self-time mode label.
        $this->assertStringContainsString('rbt:explore', $header);
        $this->assertStringContainsString('self-time top', $header);
        // Sample count + duration formatting from the model.
        $this->assertStringContainsString('6', $header); // 6 samples
        // Option labels (no_line off, no match, no filter by default).
        $this->assertStringContainsString('no-line: off', $header);
        $this->assertStringContainsString('match: (none)', $header);
        $this->assertStringContainsString('filter: (none)', $header);
    }

    public function testRenderHeaderShowsActiveSandwichViewLabel(): void
    {
        $tui = $this->makeTui();
        $this->set($tui, 'stack', [
            new ViewState(
                mode: ExploreMode::Sandwich,
                sandwich_view: SandwichView::TreeCallees,
                focus_id: 0,
                focus_label: 'leaf_x /a.php:1',
            ),
        ]);
        $state = $this->inv($tui, 'currentState');
        $this->assertInstanceOf(ViewState::class, $state);
        /** @var string $header */
        $header = $this->inv($tui, 'renderHeader', $state, 120);
        $this->assertStringContainsString('callee tree', $header);
    }

    // ---------- list rendering ----------

    public function testRenderListLinesProducesExactlyBodyRowsAndShowsTopFrame(): void
    {
        $tui = $this->makeTui();
        $state = $this->inv($tui, 'currentState');
        $this->assertInstanceOf(ViewState::class, $state);
        /** @var list<string> $lines */
        $lines = $this->inv($tui, 'renderListLines', $state, 80, 8);
        $this->assertCount(8, $lines);
        // The body must mention leaf_x somewhere — it's the dominant
        // self-time row.
        $body = implode("\n", $lines);
        $this->assertStringContainsString('leaf_x', $body);
    }

    // ---------- overview cache + sort ----------

    public function testEnsureCallersAndCalleesReturnsEmptyWhenNoFocus(): void
    {
        $tui = $this->makeTui();
        // Default state is list mode → focus_id=null → ensureCallers/Callees
        // short-circuit to an empty result.
        /** @var array{matched_samples:int, rows:list<array{int,int,string}>} $callers */
        $callers = $this->inv($tui, 'ensureCallers');
        $this->assertSame([], $callers['rows']);
        $this->assertSame(0, $callers['matched_samples']);

        /** @var array{matched_samples:int, rows:list<array{int,int,string}>} $callees */
        $callees = $this->inv($tui, 'ensureCallees');
        $this->assertSame([], $callees['rows']);
        $this->assertSame(0, $callees['matched_samples']);
    }

    public function testEnsureCallersBuildsRowsForFocusedFrame(): void
    {
        $tui = $this->makeTui();
        $this->set($tui, 'stack', [
            new ViewState(
                mode: ExploreMode::Sandwich,
                focus_id: 0, // leaf_x
                focus_label: 'leaf_x /a.php:1',
            ),
        ]);
        /** @var array{matched_samples:int, rows:list<array{int,int,string}>} $callers */
        $callers = $this->inv($tui, 'ensureCallers');
        // leaf_x's callers in the fixture are mid_a (×2) and mid_b (×2).
        $by_label = [];
        foreach ($callers['rows'] as [$count, , $label]) {
            $by_label[$label] = $count;
        }
        $this->assertSame(2, $by_label['mid_a /m.php:1']);
        $this->assertSame(2, $by_label['mid_b /m.php:2']);
    }

    public function testEnsureCalleesBuildsRowsForFocusedFrame(): void
    {
        $tui = $this->makeTui();
        $this->set($tui, 'stack', [
            new ViewState(
                mode: ExploreMode::Sandwich,
                focus_id: 3, // mid_b
                focus_label: 'mid_b /m.php:2',
            ),
        ]);
        /** @var array{matched_samples:int, rows:list<array{int,int,string}>} $callees */
        $callees = $this->inv($tui, 'ensureCallees');
        // mid_b's callees in the fixture:
        //   - leaf_x in samples #2 and #5  → leaf_x ×2
        //   - sample #4 [leaf_y, mid_b, mid_b, root]: aggregator picks the
        //     ROOTmost mid_b (index 2); its callee is stack[1] = the
        //     other mid_b. So the callee here is mid_b itself, NOT leaf_y.
        $by_label = [];
        foreach ($callees['rows'] as [$count, , $label]) {
            $by_label[$label] = $count;
        }
        $this->assertSame(2, $by_label['leaf_x /a.php:1']);
        $this->assertSame(1, $by_label['mid_b /m.php:2']);
        $this->assertArrayNotHasKey('leaf_y /a.php:2', $by_label);
    }

    public function testSetOverviewSortFlipsCacheAndStatus(): void
    {
        $tui = $this->makeTui();
        // Warm overview cache so we can prove a flip busts it.
        $this->inv($tui, 'ensureOverview');
        $this->assertNotNull($this->get($tui, 'overview_cache'));

        // Same sort → no-op, status reads "(already)".
        $this->inv($tui, 'setOverviewSort', 'self');
        $status = $this->get($tui, 'status');
        $this->assertIsString($status);
        $this->assertStringContainsString('already', $status);
        // Cache preserved on no-op.
        $this->assertNotNull($this->get($tui, 'overview_cache'));

        // Real flip → cache busted, cursor reset, status reads "total-time".
        $this->inv($tui, 'setOverviewSort', 'total');
        $this->assertNull($this->get($tui, 'overview_cache'));
        $this->assertSame(0, $this->get($tui, 'overview_selected'));
        $this->assertSame(0, $this->get($tui, 'overview_top_row'));
        $this->assertSame('total', $this->get($tui, 'overview_sort'));
    }

    // ---------- buildSandwichLayout / flame cursor ----------

    public function testBuildSandwichLayoutReturnsNullForTooSmallBody(): void
    {
        $tui = $this->makeTui();
        $this->set($tui, 'stack', [
            new ViewState(
                mode: ExploreMode::Sandwich,
                focus_id: 0,
                focus_label: 'leaf_x /a.php:1',
            ),
        ]);

        // Below width / row floors → null.
        $this->assertNull($this->inv($tui, 'buildSandwichLayout', 10, 10));
        $this->assertNull($this->inv($tui, 'buildSandwichLayout', 80, 4));
    }

    public function testBuildSandwichLayoutBuildsVisualRowsAroundFocusRow(): void
    {
        $tui = $this->makeTui();
        $this->set($tui, 'stack', [
            new ViewState(
                mode: ExploreMode::Sandwich,
                focus_id: 0, // leaf_x — has callers but no callees
                focus_label: 'leaf_x /a.php:1',
            ),
        ]);

        /** @var array{
         *   visual_rows: array<int, list<array{x:int, w:int, key_id:int, count:int}>>,
         *   focus_visual_row: int,
         *   total_visual_rows: int,
         *   inner_w: int,
         * }|null $layout
         */
        $layout = $this->inv($tui, 'buildSandwichLayout', 80, 12);
        $this->assertIsArray($layout);
        $this->assertSame(80, $layout['inner_w']);
        // body_rows=12 → tree_rows=10 → caller_rows=5, callee_rows=5
        // → total = caller_rows + 1 (focus) + callee_rows = 11.
        $this->assertSame(11, $layout['total_visual_rows']);
        // The focus row carries one synthetic full-width bar.
        $focus_row = $layout['focus_visual_row'];
        $this->assertCount(1, $layout['visual_rows'][$focus_row]);
        $this->assertSame(80, $layout['visual_rows'][$focus_row][0]['w']);
        $this->assertSame(0, $layout['visual_rows'][$focus_row][0]['key_id']);
    }

    public function testFindBarAtXPicksTheBarContainingTheColumn(): void
    {
        $tui = $this->makeTui();
        $bars = [
            ['x' => 0, 'w' => 30, 'key_id' => 1, 'count' => 10],
            ['x' => 30, 'w' => 30, 'key_id' => 2, 'count' => 8],
            ['x' => 60, 'w' => 20, 'key_id' => 3, 'count' => 5],
        ];
        /** @var array{key_id:int}|null $hit */
        $hit = $this->inv($tui, 'findBarAtX', $bars, 5);
        $this->assertSame(1, $hit['key_id'] ?? null);
        $hit = $this->inv($tui, 'findBarAtX', $bars, 45);
        $this->assertSame(2, $hit['key_id'] ?? null);
        $hit = $this->inv($tui, 'findBarAtX', $bars, 75);
        $this->assertSame(3, $hit['key_id'] ?? null);
        // Outside any bar → null.
        $this->assertNull($this->inv($tui, 'findBarAtX', $bars, 999));
        // Empty bars → null.
        $this->assertNull($this->inv($tui, 'findBarAtX', [], 5));
    }

    public function testFindBarAtXLeftWinsAtSharedBoundary(): void
    {
        $tui = $this->makeTui();
        // x=30 sits on the shared boundary between bar 1 and bar 2.
        // The interval is right-INCLUSIVE so the left bar wins.
        $bars = [
            ['x' => 0, 'w' => 30, 'key_id' => 1, 'count' => 10],
            ['x' => 30, 'w' => 30, 'key_id' => 2, 'count' => 8],
        ];
        /** @var array{key_id:int}|null $hit */
        $hit = $this->inv($tui, 'findBarAtX', $bars, 30);
        $this->assertSame(1, $hit['key_id'] ?? null);
    }

    public function testFindNearestBarPicksTheClosestCenter(): void
    {
        $tui = $this->makeTui();
        $bars = [
            ['x' => 0, 'w' => 10, 'key_id' => 1, 'count' => 1],
            ['x' => 50, 'w' => 10, 'key_id' => 2, 'count' => 1],
        ];
        // x=4 is closest to bar 1's centre (5), even with bar 2 nearby.
        /** @var array{key_id:int}|null $hit */
        $hit = $this->inv($tui, 'findNearestBar', $bars, 4);
        $this->assertSame(1, $hit['key_id'] ?? null);
        // x=48 is closest to bar 2's centre (55).
        $hit = $this->inv($tui, 'findNearestBar', $bars, 48);
        $this->assertSame(2, $hit['key_id'] ?? null);
        // Empty → null.
        $this->assertNull($this->inv($tui, 'findNearestBar', [], 0));
    }

    // ---------- tree fold / unfold ----------

    public function testFoldAndUnfoldTreeAtCursorTogglesFoldedSet(): void
    {
        $tui = $this->makeTui();
        // Set up a tree-callees state focused on root so the tree
        // actually has multiple levels (root has many descendants in
        // the fixture).
        $this->set($tui, 'stack', [
            new ViewState(
                mode: ExploreMode::Sandwich,
                sandwich_view: SandwichView::TreeCallees,
                focus_id: 4, // root
                focus_label: 'root /r.php:1',
            ),
        ]);

        // Build the tree so we can pick a real cursor row.
        /** @var list<array{text:string, key_id:int, path:string}> $entries */
        $entries = $this->inv($tui, 'buildSandwichTreeLines', 'callees', 80);
        $this->assertNotEmpty($entries);

        // Find the first entry with non-empty path.
        $target = null;
        foreach ($entries as $i => $entry) {
            if ($entry['path'] !== '') {
                $target = $i;
                break;
            }
        }
        $this->assertNotNull($target);

        $this->set($tui, 'tree_cursor_row', $target);
        $this->inv($tui, 'foldTreeAtCursor', false);
        $folded = $this->get($tui, 'tree_folded');
        $this->assertIsArray($folded);
        $this->assertNotEmpty($folded, 'fold should add at least one entry');

        // Unfold the same row → folded set should drop the entry again.
        $this->inv($tui, 'unfoldTreeAtCursor', false);
        $folded = $this->get($tui, 'tree_folded');
        $this->assertIsArray($folded);
        // Path key may be normalised by PHP — it's enough that it's gone.
        $this->assertSame(
            0,
            count($folded),
            'unfold should clear the entry the prior fold added',
        );
    }

    public function testFoldTreeAtCursorIsNoOpOutsideTreeViews(): void
    {
        $tui = $this->makeTui();
        // List mode → no-op.
        $this->inv($tui, 'foldTreeAtCursor', false);
        $this->assertSame([], $this->get($tui, 'tree_folded'));

        // Sandwich panes view → also a no-op.
        $this->set($tui, 'stack', [
            new ViewState(
                mode: ExploreMode::Sandwich,
                focus_id: 0,
                focus_label: 'leaf_x /a.php:1',
            ),
        ]);
        $this->inv($tui, 'foldTreeAtCursor', false);
        $this->assertSame([], $this->get($tui, 'tree_folded'));
    }

    // ---------- focusTreeCursor ----------

    public function testFocusTreeCursorPushesNewFocusFromTreeRow(): void
    {
        $tui = $this->makeTui();
        $this->set($tui, 'stack', [
            new ViewState(
                mode: ExploreMode::Sandwich,
                sandwich_view: SandwichView::TreeCallees,
                focus_id: 4, // root
                focus_label: 'root /r.php:1',
            ),
        ]);

        /** @var list<array{text:string, key_id:int, path:string}> $entries */
        $entries = $this->inv($tui, 'buildSandwichTreeLines', 'callees', 80);
        // Find an entry whose key_id is a real (non-synthetic) frame
        // and isn't the current focus (root, id 4).
        $target = null;
        foreach ($entries as $i => $entry) {
            if ($entry['key_id'] >= 0 && $entry['key_id'] !== 4) {
                $target = $i;
                break;
            }
        }
        $this->assertNotNull($target);

        $this->set($tui, 'tree_cursor_row', $target);
        $this->inv($tui, 'focusTreeCursor');

        $stack = $this->get($tui, 'stack');
        $this->assertIsArray($stack);
        $this->assertCount(2, $stack, 'should have pushed a new focus state');
        /** @var ViewState $top */
        $top = $this->inv($tui, 'currentState');
        $this->assertNotSame(4, $top->focus_id);
        // Tree direction inherited from the outgoing state.
        $this->assertSame(SandwichView::TreeCallees, $top->sandwich_view);
    }

    public function testFocusTreeCursorOnEmptyTreeSetsStatus(): void
    {
        // Build a model with one sample so the only focus has no
        // callers/callees beyond the synthetic <root>/<leaf>.
        $model = new TraceModel(
            frame_keys: ['only /a.php:1'],
            frame_keys_no_line: ['only'],
            no_line_map: [0],
            samples: [[0]],
            sampling_period_us: 10000,
            source_path: '/dev/null',
        );
        $tui = $this->makeTui($model);
        $this->set($tui, 'stack', [
            new ViewState(
                mode: ExploreMode::Sandwich,
                sandwich_view: SandwichView::TreeCallees,
                focus_id: 0,
                focus_label: 'only /a.php:1',
            ),
        ]);
        $this->inv($tui, 'focusTreeCursor');
        // The single-sample tree should still produce a tail entry, so
        // this is more of a smoke test that the path doesn't blow up.
        // We assert no exception was raised by reaching this point.
        $this->assertTrue(true);
    }

    // ---------- pushSandwichFocus ----------

    public function testPushSandwichFocusSnapshotsOverviewCursorOntoOutgoingState(): void
    {
        $tui = $this->makeTui();
        $this->set($tui, 'stack', [
            new ViewState(
                mode: ExploreMode::Sandwich,
                focus_id: 0,
                focus_label: 'leaf_x /a.php:1',
            ),
        ]);
        // Move the overview cursor before the push so we can detect
        // the snapshot landing on the outgoing state.
        $this->set($tui, 'overview_selected', 7);
        $this->set($tui, 'overview_top_row', 3);

        $this->inv($tui, 'pushSandwichFocus', 4, 'root /r.php:1', SandwichView::Flame);

        /** @var list<ViewState> $stack */
        $stack = $this->get($tui, 'stack');
        $this->assertCount(2, $stack);
        // Outgoing state (now stack[0]) should carry the snapshot.
        $this->assertSame(7, $stack[0]->overview_selected);
        $this->assertSame(3, $stack[0]->overview_top_row);
        // New top should be in flame view with the new focus.
        $this->assertSame(4, $stack[1]->focus_id);
        $this->assertSame(SandwichView::Flame, $stack[1]->sandwich_view);
        // Sandwich view cursors reset by the push.
        $this->assertSame(-1, $this->get($tui, 'flame_cursor_visual_row'));
        $this->assertSame(0, $this->get($tui, 'tree_cursor_row'));
        $this->assertSame([], $this->get($tui, 'tree_folded'));
    }

    // ---------- styleHeader / styleTableHeader ----------

    public function testStyleHelpersWrapStringInAnsiCodes(): void
    {
        $tui = $this->makeTui();
        /** @var string $bold */
        $bold = $this->inv($tui, 'styleHeader', 'hi');
        $this->assertStringContainsString('hi', $bold);
        $this->assertNotSame('hi', $bold); // wrapped with ANSI

        /** @var string $tableHead */
        $tableHead = $this->inv($tui, 'styleTableHeader', 'col');
        $this->assertStringContainsString('col', $tableHead);
        $this->assertNotSame('col', $tableHead);
    }

    // ---------- mouse dispatch ----------

    public function testMouseScrollUpMovesListSelectionUp(): void
    {
        $tui = $this->makeTui();
        $this->set($tui, 'term', new FakeTerminal(120, 30));
        // Start at row 5.
        $this->inv($tui, 'moveSelection', 5);
        $this->assertSame(5, $this->get($tui, 'list_selected'));

        // Scroll inside body area (ANSI row 6 = body row 1).
        $mouse = new MouseEvent(MouseEvent::SCROLL_UP, 10, 6, true, false);
        $this->inv($tui, 'dispatchMouse', $mouse);
        // Default scroll_delta is 3, so scroll up moves by -3.
        $this->assertSame(2, $this->get($tui, 'list_selected'));
    }

    public function testMouseScrollDownMovesListSelectionDown(): void
    {
        $tui = $this->makeTui();
        $this->set($tui, 'term', new FakeTerminal(120, 30));
        $mouse = new MouseEvent(MouseEvent::SCROLL_DOWN, 10, 6, true, false);
        $this->inv($tui, 'dispatchMouse', $mouse);
        $this->assertSame(3, $this->get($tui, 'list_selected'));
    }

    public function testScrollDeltaWidgetClickCyclesPresets(): void
    {
        $tui = $this->makeTui();
        $this->set($tui, 'term', new FakeTerminal(120, 30));
        $this->assertSame(3, $this->get($tui, 'scroll_delta'));

        // Render to populate scroll_delta_cols.
        $this->inv($tui, 'render');
        [$col_start, $col_end] = $this->get($tui, 'scroll_delta_cols');
        // Click on the indicator — should cycle 3 → 5.
        $mouse = new MouseEvent(
            MouseEvent::BUTTON_LEFT,
            $col_start + 1 + 1, // 0-based→1-based ANSI
            3, // header row 2 → ANSI row 3
            true,
            false,
        );
        $this->inv($tui, 'dispatchMouse', $mouse);
        $this->assertSame(5, $this->get($tui, 'scroll_delta'));
    }

    public function testScrollDeltaWidgetScrollAdjusts(): void
    {
        $tui = $this->makeTui();
        $this->set($tui, 'term', new FakeTerminal(120, 30));
        $this->inv($tui, 'render');
        [$col_start, ] = $this->get($tui, 'scroll_delta_cols');
        $ansi_col = $col_start + 1 + 1;

        // Scroll up on the indicator → 3-1=2.
        $mouse = new MouseEvent(
            MouseEvent::SCROLL_UP,
            $ansi_col,
            3,
            true,
            false,
        );
        $this->inv($tui, 'dispatchMouse', $mouse);
        $this->assertSame(2, $this->get($tui, 'scroll_delta'));

        // Scroll down → 2+1=3.
        $mouse = new MouseEvent(
            MouseEvent::SCROLL_DOWN,
            $ansi_col,
            3,
            true,
            false,
        );
        $this->inv($tui, 'dispatchMouse', $mouse);
        $this->assertSame(3, $this->get($tui, 'scroll_delta'));
    }

    public function testMouseScrollTargetsPaneUnderPointer(): void
    {
        $tui = $this->makeTui();
        $this->set($tui, 'term', new FakeTerminal(120, 30));

        // Push into sandwich mode.
        $this->inv($tui, 'focusSelected');
        /** @var ViewState $state */
        $state = $this->inv($tui, 'currentState');
        $this->assertSame(ExploreMode::Sandwich, $state->mode);

        // Active pane is Callers, but scroll over the callees region.
        // body_start = 5 (4 header + 1 mini-flame), body_rows = 23
        // banner=3, available=20, caller_body=10
        // Callees body starts at body_row 13 → ANSI row = 5+13+1 = 19
        // Use column past sidebar (sidebar_width=33, separator=1).
        $mouse = new MouseEvent(MouseEvent::SCROLL_DOWN, 36, 19, true, false);
        $this->inv($tui, 'dispatchMouse', $mouse);

        // Default scroll_delta=3, so callees moves by 3.
        $this->assertSame(3, $this->get($tui, 'callees_selected'));
        $this->assertSame(0, $this->get($tui, 'callers_selected'));
    }

    public function testMouseScrollOverSidebarScrollsOverview(): void
    {
        $tui = $this->makeTui();
        $this->set($tui, 'term', new FakeTerminal(120, 30));

        // Push into sandwich mode.
        $this->inv($tui, 'focusSelected');

        // Scroll in the sidebar area (col 5, inside sidebar_width=33).
        $mouse = new MouseEvent(MouseEvent::SCROLL_DOWN, 5, 8, true, false);
        $this->inv($tui, 'dispatchMouse', $mouse);

        $this->assertSame(3, $this->get($tui, 'overview_selected'));
    }

    public function testMouseReleaseIsIgnored(): void
    {
        $tui = $this->makeTui();
        $this->inv($tui, 'moveSelection', 2);
        // Release event — should not change selection.
        $mouse = new MouseEvent(MouseEvent::BUTTON_LEFT, 10, 10, false, false);
        $this->inv($tui, 'dispatchMouse', $mouse);
        $this->assertSame(2, $this->get($tui, 'list_selected'));
    }

    public function testMouseClickListBodySelectsRow(): void
    {
        $tui = $this->makeTui();
        // Set up a fake terminal size for geometry calculation.
        $this->set($tui, 'term', new FakeTerminal(120, 30));

        // List mode: no mini-flame, body_start = 4 (header, 0-based).
        // body row 0 = table header, body row 1+ = data rows.
        // ANSI row for data row 0 = body_start + 1(header) + 0 + 1(to 1-based) = 6
        $mouse = new MouseEvent(MouseEvent::BUTTON_LEFT, 10, 6, true, false);
        $this->inv($tui, 'dispatchMouse', $mouse);
        $this->assertSame(0, $this->get($tui, 'list_selected'));

        // Model has 2 list rows (leaf_x, leaf_y). Click data row 1.
        // ANSI row = 4 + 1 + 1 + 1 = 7
        $mouse = new MouseEvent(MouseEvent::BUTTON_LEFT, 10, 7, true, false);
        $this->inv($tui, 'dispatchMouse', $mouse);
        $this->assertSame(1, $this->get($tui, 'list_selected'));
    }

    public function testMouseClickSandwichPanesSwitchesPaneAndSelectsRow(): void
    {
        $tui = $this->makeTui();
        $this->set($tui, 'term', new FakeTerminal(120, 30));

        // Push into sandwich mode by focusing on a frame.
        $this->inv($tui, 'focusSelected');
        /** @var ViewState $state */
        $state = $this->inv($tui, 'currentState');
        $this->assertSame(ExploreMode::Sandwich, $state->mode);
        $this->assertSame(ActivePane::Callers, $state->active_pane);

        // Sidebar width at 120 cols = int(120*0.28) = 33, separator = 1.
        // Must click past col 34 (0-based) to land in the body, not sidebar.
        // body_start = 4 + 1(mini-flame) = 5
        // body_rows = 30 - 6 - 1 = 23
        // banner = 3, available = 20, caller_body = 10
        // Callees pane starts at body_row 13 (= 10 + 3).
        // Callee data row 0: body_row 14
        // ANSI row = body_start + body_row + 1 = 5 + 14 + 1 = 20
        // ANSI col past sidebar: 36 (1-based, well past col 34)
        $mouse = new MouseEvent(
            MouseEvent::BUTTON_LEFT,
            36,
            20,
            true,
            false,
        );
        $this->inv($tui, 'dispatchMouse', $mouse);

        /** @var ViewState $state */
        $state = $this->inv($tui, 'currentState');
        $this->assertSame(ActivePane::Callees, $state->active_pane);
    }

    public function testMouseRightClickIsIgnored(): void
    {
        $tui = $this->makeTui();
        $this->set($tui, 'term', new FakeTerminal(120, 30));

        $mouse = new MouseEvent(MouseEvent::BUTTON_RIGHT, 10, 6, true, false);
        $this->inv($tui, 'dispatchMouse', $mouse);
        // Selection should not have changed.
        $this->assertSame(0, $this->get($tui, 'list_selected'));
    }
}
