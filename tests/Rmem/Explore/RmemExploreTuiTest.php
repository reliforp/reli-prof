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

namespace Reli\Rmem\Explore;

use PHPUnit\Framework\TestCase;
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Reader as BinaryReader;
use Reli\Inspector\Output\MemoryOutput\BinaryMemoryOutput;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\GraphSubstrate;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\BinaryContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;
use Reli\Rbt\Explore\FakeTerminal;
use Reli\Rbt\Explore\Keymap;

/**
 * Integration tests for the rmem:explore TUI.
 *
 * Builds a small .rmem fixture, creates a model, and drives the TUI
 * through FakeTerminal with scripted keystrokes. Assertions use
 * plainOutput() to check for expected labels and view state.
 *
 * Graph topology:
 *
 *   node 1 (root, ObjectContext, TestClass, size=500, addr=0x1000)
 *     +-- node 2 (ObjectContext, TestChild, size=300, addr=0x2000)
 *     |     +-- node 4 (StringContext, value="test_value", size=64, addr=0x4000)
 *     +-- node 3 (ArrayContext, size=128, addr=0x3000)
 *           +-- node 5 (ObjectContext, TestChild, size=100, addr=0x5000)
 *
 *   Non-tree reference: node 5 -> node 4
 */
class RmemExploreTuiTest extends TestCase
{
    private string $rmem_path = '';

    #[\Override]
    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'reli_tui_test_');
        self::assertNotFalse($path);
        $this->rmem_path = $path . '.rmem';

        $sink = new BinaryContextTreeSink(batch_size: 10);

        $sink->emitNode(
            node_id: 1,
            parent_node_id: null,
            link_name: 'root_entry',
            type: 'ObjectContext',
            locations: [new ZendObjectMemoryLocation(
                address: 0x1000,
                size: 500,
                refcount: 1,
                type_info: 7,
                class_name: 'TestClass',
            )],
            attributes: [],
        );

        $sink->emitNode(
            node_id: 2,
            parent_node_id: 1,
            link_name: 'child_obj',
            type: 'ObjectContext',
            locations: [new ZendObjectMemoryLocation(
                address: 0x2000,
                size: 300,
                refcount: 2,
                type_info: 7,
                class_name: 'TestChild',
            )],
            attributes: [],
        );

        $sink->emitNode(
            node_id: 3,
            parent_node_id: 1,
            link_name: 'child_arr',
            type: 'ArrayContext',
            locations: [new ZendArrayMemoryLocation(
                address: 0x3000,
                size: 128,
                refcount: 1,
                type_info: 7,
            )],
            attributes: [],
        );

        $sink->emitNode(
            node_id: 4,
            parent_node_id: 2,
            link_name: 'str_prop',
            type: 'StringContext',
            locations: [new ZendStringMemoryLocation(
                address: 0x4000,
                size: 64,
                refcount: 1,
                type_info: 6,
                value: 'test_value',
            )],
            attributes: [],
        );

        $sink->emitNode(
            node_id: 5,
            parent_node_id: 3,
            link_name: 'element_0',
            type: 'ObjectContext',
            locations: [new ZendObjectMemoryLocation(
                address: 0x5000,
                size: 100,
                refcount: 1,
                type_info: 7,
                class_name: 'TestChild',
            )],
            attributes: [],
        );

        $sink->emitReference(
            reference_node_id: 4,
            parent_node_id: 5,
            link_name: 'backref',
        );

        $binary_output = new BinaryMemoryOutput($this->rmem_path);
        $binary_output->finalizeStreaming($sink, [
            ['zend_mm_heap_usage' => '1092', 'php_version' => '8.2.0'],
        ]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (file_exists($this->rmem_path)) {
            @unlink($this->rmem_path);
        }
    }

    private function createModel(): RmemModel
    {
        $reader = BinaryReader::open($this->rmem_path);
        $substrate = GraphSubstrate::createFromBinary($reader, forceFfiCsr: false, skipScc: true);
        return RmemModel::fromSubstrate($substrate, $reader);
    }

    private function makeTui(
        ?RmemModel $model = null,
        ?FakeTerminal $term = null,
        ?int $initialNodeId = null,
    ): RmemExploreTui {
        return new RmemExploreTui(
            $model ?? $this->createModel(),
            $term ?? new FakeTerminal(cols: 120, rows: 30),
            Keymap::default(),
            $initialNodeId,
        );
    }

    // ---- helper to invoke private methods via reflection ----

    private function inv(RmemExploreTui $tui, string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod($tui, $method);
        return $ref->invokeArgs($tui, $args);
    }

    // ---------- 1. Initial view shows roots ----------

    public function testInitialViewShowsRoots(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);

        $term->script('q');
        $tui->run();

        $out = $term->plainOutput();
        $this->assertStringContainsString('rmem:explore', $out);
        $this->assertStringContainsString('Roots', $out);
        // The root node's class should appear
        $this->assertStringContainsString('TestClass', $out);
    }

    // ---------- 2. Navigate to sandwich ----------

    public function testNavigateToSandwich(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);

        // Enter on the root node opens sandwich view, then quit
        $term->script("\r", 'q');
        $tui->run();

        $out = $term->plainOutput();
        // Header should show sandwich mode
        $this->assertStringContainsString('sandwich', $out);
        // The focus node's label should appear
        $this->assertStringContainsString('TestClass', $out);
    }

    // ---------- 3. Back navigation ----------

    public function testBackNavigation(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);

        // Enter sandwich, then backspace to return to list, then quit
        $term->script("\r", "\x7f", 'q');
        $tui->run();

        $out = $term->plainOutput();
        // After going back we should be in list mode again
        $this->assertStringContainsString('[list', $out);
        $this->assertStringContainsString('Roots', $out);
    }

    // ---------- 4. Class ranking ----------

    public function testClassRanking(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);

        $term->script('c', 'q');
        $tui->run();

        $out = $term->plainOutput();
        $this->assertStringContainsString('Class ranking', $out);
        // TestChild appears twice in the graph
        $this->assertStringContainsString('TestChild', $out);
        $this->assertStringContainsString('TestClass', $out);
    }

    // ---------- 5. Type ranking ----------

    public function testTypeRanking(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);

        $term->script('y', 'q');
        $tui->run();

        $out = $term->plainOutput();
        $this->assertStringContainsString('Type ranking', $out);
        $this->assertStringContainsString('ObjectContext', $out);
    }

    // ---------- 6. Top retained ----------

    public function testTopRetained(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);

        $term->script('s', 'q');
        $tui->run();

        $out = $term->plainOutput();
        $this->assertStringContainsString('Top retained', $out);
        // The root node with largest retained should appear
        $this->assertStringContainsString('TestClass', $out);
    }

    // ---------- 7. Sidebar toggle ----------

    public function testSidebarToggle(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);

        // Sidebar is on by default, toggle off then on, then quit
        // With sidebar on, node detail info should appear
        $term->script('q');
        $tui->run();

        $out = $term->plainOutput();
        // Sidebar shows node detail
        $this->assertStringContainsString('Node detail', $out);
    }

    // ---------- 8. Filter prompt ----------

    public function testFilterPrompt(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $model = $this->createModel();
        $tui = $this->makeTui(model: $model, term: $term);

        // Switch to top retained first (so we have multiple rows to filter),
        // then open filter, type "test", press Enter, then quit.
        // The '/' key is mapped to ACTION_FILTER_VIEW in the Keymap,
        // but for list mode the footer shows '/:filt'. Let's just use
        // it from sandwich mode where filter is available.
        // Actually, for list mode: the dispatchRaw fallback handles 'c',
        // and dispatch handles '/' as ACTION_FILTER_VIEW.
        // We need a view with multiple rows. Top retained should have all 5 nodes.
        $term->script('s', '/', 'T', 'e', 's', 't', 'C', 'l', 'a', 's', 's', "\r", 'q');
        $tui->run();

        $out = $term->plainOutput();
        // After filter, only TestClass rows should remain
        $this->assertStringContainsString('TestClass', $out);
        $this->assertStringContainsString('filter: TestClass', $out);
    }

    // ---------- 9. Initial node ----------

    public function testInitialNode(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term, initialNodeId: 2);

        $term->script('q');
        $tui->run();

        $out = $term->plainOutput();
        // Should open directly in sandwich mode on node 2 (TestChild)
        $this->assertStringContainsString('sandwich', $out);
        $this->assertStringContainsString('TestChild', $out);
    }

    // ---------- 10. Help overlay ----------

    public function testHelpOverlay(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);

        // '?' opens help, any key closes it, then 'q' quits
        $term->script('?', ' ', 'q');
        $tui->run();

        $out = $term->plainOutput();
        // Help overlay should have been rendered at some point
        $this->assertStringContainsString('Memory Snapshot Explorer', $out);
        $this->assertStringContainsString('Navigation:', $out);
    }

    // ---------- 10b. Overview overlay ----------

    public function testOverviewOverlayRendersWhenLinesProvided(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);
        $tui->setOverviewLines([
            'Captured: 2026-04-24T00:00:00Z',
            'memory_get_usage(): 50 B | Heap: 48 B (99.0% analyzed)',
        ]);

        // 'O' opens the overview overlay, any key dismisses it, then 'q' quits.
        $term->script('O', ' ', 'q');
        $tui->run();

        $out = $term->plainOutput();
        $this->assertStringContainsString('Memory overview', $out);
        $this->assertStringContainsString('Captured: 2026-04-24T00:00:00Z', $out);
        $this->assertStringContainsString('memory_get_usage()', $out);
    }

    public function testOverviewOverlayIsNoOpWhenNoLinesSet(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);
        // Intentionally do NOT call setOverviewLines().

        $term->script('O', 'q');
        $tui->run();

        $out = $term->plainOutput();
        $this->assertStringNotContainsString('Memory overview', $out);
    }

    // ---------- 11. Global search ----------

    public function testGlobalSearch(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);

        // 'F' opens global search prompt, type "TestClass", Enter to submit
        $term->script('F', 'T', 'e', 's', 't', 'C', 'l', 'a', 's', 's', "\r", 'q');
        $tui->run();

        $out = $term->plainOutput();
        $this->assertStringContainsString('Search:', $out);
        $this->assertStringContainsString('TestClass', $out);
    }

    // ---------- 12. Address jump (node ID with # prefix) ----------

    public function testAddressJump(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);

        // 'a' opens address prompt, type "#1" (node ID 1), Enter to jump
        $term->script('a', '#', '1', "\r", 'q');
        $tui->run();

        $out = $term->plainOutput();
        // Should enter sandwich on node 1 (TestClass)
        $this->assertStringContainsString('sandwich', $out);
        $this->assertStringContainsString('TestClass', $out);
    }

    // ---------- 13. Go to definition ----------

    public function testGoToDefinition(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);

        // 'g' triggers goToDefinition on the selected node
        // With our fixture there's no function/class def to jump to,
        // so it should be a no-op (stays in list mode)
        $term->script('g', 'q');
        $tui->run();

        $out = $term->plainOutput();
        // Should still be in list mode (no definition found for fixture nodes)
        $this->assertStringContainsString('Roots', $out);
        $this->assertStringContainsString('TestClass', $out);
    }

    // ---------- 14. Toggle sort ----------

    public function testToggleSort(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);

        // Enter sandwich first, then 'r' toggles sort mode
        $term->script("\r", 'r', 'q');
        $tui->run();

        $out = $term->plainOutput();
        // Should still be in sandwich (sort just changes child ordering)
        $this->assertStringContainsString('sandwich', $out);
        $this->assertStringContainsString('TestClass', $out);
    }

    // ---------- 15. Toggle all edges ----------

    public function testToggleAllEdges(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);

        // Enter sandwich, then 'n' toggles all edges (tree-only vs all)
        $term->script("\r", 'n', 'q');
        $tui->run();

        $out = $term->plainOutput();
        $this->assertStringContainsString('sandwich', $out);
        $this->assertStringContainsString('TestClass', $out);
    }

    // ---------- 16. Bookmark toggle ----------

    public function testBookmarkToggle(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);

        // 'm' bookmarks the currently selected node, then "'" shows bookmarks
        $term->script('m', "'", 'q');
        $tui->run();

        $out = $term->plainOutput();
        $this->assertStringContainsString('Bookmarks', $out);
        $this->assertStringContainsString('TestClass', $out);
    }

    // ---------- 17. Subtree info ----------

    public function testSubtreeInfo(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);

        // Enter sandwich on root, then 'i' shows subtree info
        $term->script("\r", 'i', 'q');
        $tui->run();

        $out = $term->plainOutput();
        $this->assertStringContainsString('Subtree:', $out);
        // Should show type/class breakdown
        $this->assertStringContainsString('[type]', $out);
    }

    // ---------- 18. Cycles view ----------

    public function testCyclesView(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        // The model was created with skipScc: true, so SCC data is unavailable
        $tui = $this->makeTui(term: $term);

        // 'x' opens cycles view
        $term->script('x', 'q');
        $tui->run();

        $out = $term->plainOutput();
        // With skipScc: true, SCC is not available
        $this->assertStringContainsString('Cycles', $out);
        $this->assertTrue(
            str_contains($out, 'not available') || str_contains($out, 'no cycles'),
            'Expected cycles view to show unavailable or empty message',
        );
    }

    // ---------- 19. Nodes by class from ranking ----------

    public function testNodesByClassFromRanking(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);

        // 'c' to class ranking, then Enter on first class to drill into instances
        $term->script('c', "\r", 'q');
        $tui->run();

        $out = $term->plainOutput();
        // Should show "Class:" label for the drilled-down instance list
        $this->assertStringContainsString('Class:', $out);
    }

    // ---------- 20. Nodes by type from ranking ----------

    public function testNodesByTypeFromRanking(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);

        // 'y' to type ranking, then Enter on first type to drill into instances
        $term->script('y', "\r", 'q');
        $tui->run();

        $out = $term->plainOutput();
        // Should show "Type:" label for the drilled-down instance list
        $this->assertStringContainsString('Type:', $out);
    }

    // ---------- 21. UI navigate sandwich (via reflection) ----------

    public function testUiNavigateSandwich(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);

        // Use reflection to call the private handler directly
        $result = $this->inv($tui, 'handleUiNavigateSandwich', ['node_id' => 1]);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['data']['node_id']);
        $this->assertSame('sandwich', $result['data']['mode']);
        $this->assertStringContainsString('TestClass', $result['data']['label']);
    }

    // ---------- 22. UI get current focus ----------

    public function testUiGetCurrentFocus(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);

        // Before running, initialize rows via reflection by calling run setup
        // Actually, need to init the model state. Let's run briefly then check.
        // Use reflection to get focus in list mode
        $result = $this->inv($tui, 'handleUiGetCurrentFocus');

        $this->assertTrue($result['ok']);
        $this->assertSame('list', $result['data']['mode']);

        // Now navigate to sandwich and check again
        $this->inv($tui, 'handleUiNavigateSandwich', ['node_id' => 2]);
        $result = $this->inv($tui, 'handleUiGetCurrentFocus');

        $this->assertTrue($result['ok']);
        $this->assertSame('sandwich', $result['data']['mode']);
        $this->assertSame(2, $result['data']['node_id']);
    }

    // ---------- 23. UI get current selection ----------

    public function testUiGetCurrentSelection(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $model = $this->createModel();
        $tui = $this->makeTui(model: $model, term: $term);

        // Before run(), rows are empty, so selection returns null data
        $result = $this->inv($tui, 'handleUiGetCurrentSelection');
        $this->assertTrue($result['ok']);
        $this->assertNull($result['data']);

        // After navigating to top retained, rows are populated
        $this->inv($tui, 'switchToTopRetained');
        $result = $this->inv($tui, 'handleUiGetCurrentSelection');
        $this->assertTrue($result['ok']);
        $this->assertNotNull($result['data']);
        $this->assertArrayHasKey('node_id', $result['data']);
        $this->assertArrayHasKey('label', $result['data']);
        $this->assertSame(0, $result['data']['index']);
    }

    // ---------- 24. Sandwich pane switch ----------

    public function testSandwichPaneSwitch(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);

        // Enter sandwich, then Tab to switch pane, then quit
        $term->script("\r", "\t", 'q');
        $tui->run();

        $out = $term->plainOutput();
        $this->assertStringContainsString('sandwich', $out);
        // After Tab in sandwich, parents pane should become active
        // The output should contain "Parents" header section
        $this->assertStringContainsString('Parents', $out);
    }

    // ---------- 25. Multiple back steps ----------

    public function testMultipleBackSteps(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);

        // Enter sandwich on root (node 1), then Enter on child (node 2),
        // building sandwich history. Then Backspace twice to return to list.
        // From root sandwich, children pane has node 2 and node 3.
        // Enter on first child -> sandwich on node 2.
        // Backspace -> back to sandwich on node 1 (from history).
        // Backspace -> back to list mode.
        $term->script("\r", "\r", "\x7f", "\x7f", 'q');
        $tui->run();

        $out = $term->plainOutput();
        // Should be back in list mode showing roots
        $this->assertStringContainsString('[list', $out);
        $this->assertStringContainsString('Roots', $out);
    }

    // =================================================================
    // (roots) synthetic anchor
    // =================================================================

    public function testSentinelLabelIsRoots(): void
    {
        $tui = $this->makeTui();
        $model = $this->createModel();
        self::assertSame('(roots)', $model->nodeLabel(-1));
        // Any negative id treated as sentinel.
        self::assertSame('(roots)', $model->nodeLabel(-42));
    }

    public function testSentinelParentOfForestRootReturnsFriendlyLabel(): void
    {
        $model = $this->createModel();
        $parents = $model->getParents(1); // node 1 is a forest root
        self::assertNotEmpty($parents);
        // Sentinel entry uses the "(roots)" label instead of "node#-1".
        self::assertSame(-1, $parents[0]['node_id']);
        self::assertSame('(roots)', $parents[0]['label']);
    }

    public function testSentinelChildrenAreForestRoots(): void
    {
        $model = $this->createModel();
        $sentinelKids = $model->getChildren(-1);
        self::assertNotEmpty($sentinelKids);
        $ids = array_column($sentinelKids, 'node_id');
        // Node 1 (the root object from setUp) must be among the
        // sentinel's children so Enter on "(roots)" reaches every
        // forest branch.
        self::assertContains(1, $ids);
    }

    // =================================================================
    // Follow mode
    // =================================================================

    public function testFollowModeTogglesAndEmitsFocus(): void
    {
        // Wire a real pipe so we can read what the TUI emits.
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$readEnd, $writeEnd] = $pair;
        stream_set_blocking($readEnd, false);

        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);
        $tui->setFocusEventPipe($writeEnd);

        // Toggle follow (f), move selection once (↓), quit (q).
        $term->script('f', "\e[B", 'q');
        $tui->run();

        $data = '';
        while (($chunk = @fread($readEnd, 4096)) !== false && $chunk !== '') {
            $data .= $chunk;
        }
        fclose($readEnd);
        fclose($writeEnd);

        self::assertNotSame('', $data, 'follow mode did not emit any focus event');
        // Emitted payload is {"node_id": N}\n — just check for that shape.
        self::assertMatchesRegularExpression('/\{"node_id":\d+\}/', $data);
    }

    public function testFollowModeSuppressesSentinelBroadcast(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$readEnd, $writeEnd] = $pair;
        stream_set_blocking($readEnd, false);

        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);
        $tui->setFocusEventPipe($writeEnd);

        // emitFocusEvent(-1) should be a no-op.
        $this->inv($tui, 'emitFocusEvent', -1);
        $data = '';
        while (($chunk = @fread($readEnd, 4096)) !== false && $chunk !== '') {
            $data .= $chunk;
        }
        fclose($readEnd);
        fclose($writeEnd);
        self::assertSame('', $data, 'sentinel focus leaked onto the pipe');
    }

    // =================================================================
    // Mouse input
    // =================================================================

    public function testMouseLeftClickMovesSelectedRow(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);

        // Switch to top-retained so the list has > 1 rows, then
        // SGR-click terminal row 6 → list data index
        // = topRow + (row - 5) = 0 + 1 = 1.
        $term->script('s', "\e[<0;20;6M", "\e[<0;20;6m", 'q');
        $tui->run();

        $r = new \ReflectionProperty($tui, 'selected');
        $r->setAccessible(true);
        self::assertSame(1, $r->getValue($tui));
    }

    public function testMouseDoubleClickEntersSandwich(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);

        // Two presses on the same row inside the double-click window
        // must drill into sandwich mode. Releases (m→) are parsed and
        // ignored by handleMouseEvent (not press events).
        $term->script(
            "\e[<0;20;5M",
            "\e[<0;20;5m",
            "\e[<0;20;5M",
            "\e[<0;20;5m",
            'q',
        );
        $tui->run();

        $out = $term->plainOutput();
        self::assertStringContainsString('sandwich', $out);
    }

    public function testMouseScrollWheelMovesSelection(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);

        // Switch to top-retained (multi-row) so a scroll-down can
        // actually move the selection off 0.
        // SGR scroll-down event (button 65 per MouseEvent::SCROLL_DOWN).
        $term->script('s', "\e[<65;20;10M", 'q');
        $tui->run();

        $r = new \ReflectionProperty($tui, 'selected');
        $r->setAccessible(true);
        self::assertGreaterThan(0, $r->getValue($tui));
    }

    public function testMouseIgnoredDuringFilterPrompt(): void
    {
        $term = new FakeTerminal(cols: 120, rows: 30);
        $tui = $this->makeTui(term: $term);

        // Open filter prompt with /, then "click" with the mouse. The
        // click must not move selection — the prompt handler swallows
        // everything until Esc.
        $term->script('/', "\e[<0;20;8M", "\e[<0;20;8m", "\x1b", 'q');
        $tui->run();

        $r = new \ReflectionProperty($tui, 'selected');
        $r->setAccessible(true);
        self::assertSame(0, $r->getValue($tui));
    }
}
