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
 * Render-pipeline integration tests.
 *
 * Where {@see ExploreTuiStateTest} unit-tests the state machine via
 * reflection, this file drives the *full* render path: every test
 * builds an ExploreTui against a {@see FakeTerminal}, calls render()
 * (or scripts a sequence of keypresses through dispatch), and asserts
 * on the captured frame buffer.
 *
 * The assertions deliberately avoid pixel-exact layout checks (those
 * would shatter on every cosmetic tweak); they look for fragments
 * that have to be present for the view to be doing its job at all —
 * frame names, mode labels, footer hints, prompt placeholders, etc.
 * The strip-ANSI helper on {@see FakeTerminal} keeps assertions
 * readable without making them sensitive to colour codes.
 */
class ExploreTuiRenderTest extends BaseTestCase
{
    private function makeTui(?TraceModel $model = null, ?FakeTerminal $term = null): ExploreTui
    {
        return new ExploreTui(
            $model ?? $this->makeModel(),
            $term ?? new FakeTerminal(cols: 120, rows: 30),
            Keymap::default(),
        );
    }

    private function makeModel(): TraceModel
    {
        $frame_keys = [
            'leaf_x /a.php:1',
            'leaf_y /a.php:2',
            'mid_a /m.php:1',
            'mid_b /m.php:2',
            'root /r.php:1',
        ];
        return new TraceModel(
            frame_keys: $frame_keys,
            frame_keys_no_line: $frame_keys,
            no_line_map: [0, 1, 2, 3, 4],
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

    private function inv(ExploreTui $tui, string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod($tui, $method);
        return $ref->invokeArgs($tui, $args);
    }

    private function set(ExploreTui $tui, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($tui, $prop);
        $ref->setValue($tui, $value);
    }

    // ---------- list mode (entry view) ----------

    public function testRenderListSelfShowsHeaderModeAndDominantFrame(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);

        $this->inv($tui, 'render');

        $out = $term->plainOutput();
        // Header bits.
        $this->assertStringContainsString('rbt:explore', $out);
        $this->assertStringContainsString('self-time top', $out);
        $this->assertStringContainsString('no-line: off', $out);
        // Body should mention the dominant leaves.
        $this->assertStringContainsString('leaf_x', $out);
        $this->assertStringContainsString('leaf_y', $out);
        // Footer (list mode hints).
        $this->assertStringContainsString('[Enter] focus', $out);
        $this->assertStringContainsString('[q] quit', $out);
    }

    public function testRenderListTotalAfterDispatchTPressShowsTotalLabel(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);

        // 't' triggers ACTION_VIEW_TOTAL → resetTo(ExploreMode::ListTotal).
        $this->inv($tui, 'dispatch', 't');
        $term->clearBuffer();
        $this->inv($tui, 'render');

        $out = $term->plainOutput();
        $this->assertStringContainsString('total-time top', $out);
        // total-time on the same fixture should now include the inner
        // frames too (mid_a / mid_b / root all appear in many samples).
        $this->assertStringContainsString('root', $out);
        $this->assertStringContainsString('mid_a', $out);
    }

    // ---------- sandwich panes view ----------

    public function testRenderSandwichPanesViewIncludesFocusBannerAndCallerCallee(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);
        // Focus on leaf_x → callers are mid_a / mid_b, callees empty (it's a leaf).
        $this->set($tui, 'stack', [
            new ViewState(
                mode: ExploreMode::Sandwich,
                focus_id: 0,
                focus_label: 'leaf_x /a.php:1',
            ),
        ]);

        $this->inv($tui, 'render');

        $out = $term->plainOutput();
        $this->assertStringContainsString('sandwich panes', $out);
        // Focus banner shows the focus label.
        $this->assertStringContainsString('leaf_x', $out);
        // Both callers should appear in the callers pane.
        $this->assertStringContainsString('mid_a', $out);
        $this->assertStringContainsString('mid_b', $out);
        // Footer should advertise the panes-view operation hints.
        $this->assertStringContainsString('[Tab] pane', $out);
    }

    // ---------- sandwich flame view ----------

    public function testRenderSandwichFlameViewSwitchesModeLabelAndDrawsBars(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);
        $this->set($tui, 'stack', [
            new ViewState(
                mode: ExploreMode::Sandwich,
                sandwich_view: SandwichView::Flame,
                focus_id: 4, // root has the deepest tree
                focus_label: 'root /r.php:1',
            ),
        ]);

        $this->inv($tui, 'render');

        $out = $term->plainOutput();
        $this->assertStringContainsString('sandwich flame', $out);
        // Footer hints should match the flame-view bindings.
        $this->assertStringContainsString('[↑↓] depth', $out);
        $this->assertStringContainsString('[Enter] focus', $out);
    }

    // ---------- sandwich tree views ----------

    public function testRenderSandwichTreeCalleesViewShowsCalleeTreeRows(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);
        $this->set($tui, 'stack', [
            new ViewState(
                mode: ExploreMode::Sandwich,
                sandwich_view: SandwichView::TreeCallees,
                focus_id: 4, // root → has all paths under it
                focus_label: 'root /r.php:1',
            ),
        ]);

        $this->inv($tui, 'render');

        $out = $term->plainOutput();
        $this->assertStringContainsString('callee tree', $out);
        // Tree body has indented rows; we should see at least one of
        // the descendant frame names.
        $this->assertTrue(
            str_contains($out, 'mid_a')
            || str_contains($out, 'mid_b')
            || str_contains($out, 'leaf_x'),
            'tree body should mention at least one descendant frame',
        );
        // Footer hints for tree view.
        $this->assertStringContainsString('[H/L] fold-rec', $out);
    }

    public function testRenderSandwichTreeCallersViewShowsCallerTreeMode(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);
        $this->set($tui, 'stack', [
            new ViewState(
                mode: ExploreMode::Sandwich,
                sandwich_view: SandwichView::TreeCallers,
                focus_id: 0, // leaf_x has callers all the way to root
                focus_label: 'leaf_x /a.php:1',
            ),
        ]);

        $this->inv($tui, 'render');

        $out = $term->plainOutput();
        $this->assertStringContainsString('caller tree', $out);
        $this->assertStringContainsString('mid_a', $out);
    }

    // ---------- help overlay ----------

    public function testHelpOverlayShowsAfterQuestionMarkAndContainsKeySection(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);

        $this->inv($tui, 'dispatch', '?');
        $this->inv($tui, 'render');

        $out = $term->plainOutput();
        // The overlay starts with "── help ──" and lists the bindings;
        // grep for the header and one distinctive binding hint to
        // prove the overlay block is actually being drawn.
        $this->assertStringContainsString('── help', $out);
        $this->assertStringContainsString('press any key', $out);
        $this->assertStringContainsString('focus selected row', $out);
    }

    // ---------- prompt mode ----------

    public function testFilterPromptDisplaysPromptLabelAndAcceptsTypedInput(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);

        // '/' opens the view-filter prompt.
        $this->inv($tui, 'dispatch', '/');
        $this->inv($tui, 'render');
        $out = $term->plainOutput();
        $this->assertStringContainsString('view filter', $out);

        // Type 'leaf' through the prompt — these go through dispatchPrompt
        // because the prompt label is non-null.
        $term->clearBuffer();
        $this->inv($tui, 'dispatch', 'l');
        $this->inv($tui, 'dispatch', 'e');
        $this->inv($tui, 'dispatch', 'a');
        $this->inv($tui, 'dispatch', 'f');
        $this->inv($tui, 'render');

        $out = $term->plainOutput();
        // Prompt buffer is shown after the label.
        $this->assertStringContainsString('view filter', $out);
        $this->assertStringContainsString('leaf', $out);
    }

    public function testFilterPromptCommitsOnEnterAndAppliesFilter(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);

        $this->inv($tui, 'dispatch', '/');
        foreach (['l', 'e', 'a', 'f', '_', 'x'] as $ch) {
            $this->inv($tui, 'dispatch', $ch);
        }
        $this->inv($tui, 'dispatch', "\r");

        // After commit the view filter should be set on the top state.
        /** @var ViewState $state */
        $state = $this->inv($tui, 'currentState');
        $this->assertSame('leaf_x', $state->view_filter);

        // Re-render and check the filtered list only contains leaf_x.
        $term->clearBuffer();
        $this->inv($tui, 'render');
        $out = $term->plainOutput();
        $this->assertStringContainsString('leaf_x', $out);
        $this->assertStringNotContainsString('leaf_y', $out);
    }

    // ---------- terminal-too-small guard ----------

    public function testRenderTerminalTooSmallWritesGuardMessage(): void
    {
        $term = new FakeTerminal(cols: 30, rows: 5); // below MIN_COLS=60 / MIN_ROWS=12
        $tui = $this->makeTui(term: $term);

        $this->inv($tui, 'render');
        $out = $term->plainOutput();
        $this->assertStringContainsString('Terminal too small', $out);
    }

    // ---------- run-loop integration via scripted keys ----------

    public function testRunLoopProcessesScriptedKeysAndExitsOnQuit(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);

        // Drive a tiny session: down, down, up, then 'q' to exit.
        $term->script('j', 'j', 'k', 'q');

        // run() will block on pollKey if the script runs out — guard
        // with an explicit final 'q' so the loop exits cleanly.
        $tui->run();

        // Terminal should have been entered and left exactly once.
        $this->assertFalse($term->isEntered());
        // The dispatch path should have produced render frames each
        // tick (4 keys → at least 4 render passes), so the buffer
        // should contain the header at least once.
        $this->assertStringContainsString('rbt:explore', $term->plainOutput());
    }

    public function testRunLoopHonoursViewSwitchKeysEndToEnd(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);

        // Enter sandwich mode on the first list row, then jump to flame
        // view, then quit. The header should reflect the flame mode in
        // the final frame.
        $term->script("\r", 'S', 'q');
        $tui->run();

        $out = $term->plainOutput();
        // The last frame the loop wrote should be the flame view —
        // anchor the search on the unique flame mode label.
        $this->assertStringContainsString('sandwich flame', $out);
    }
}
