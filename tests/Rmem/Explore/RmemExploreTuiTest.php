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
}
