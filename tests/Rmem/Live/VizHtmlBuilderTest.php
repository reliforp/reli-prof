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

namespace Reli\Rmem\Live;

use PHPUnit\Framework\TestCase;
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Reader as BinaryReader;
use Reli\Inspector\Output\MemoryOutput\BinaryMemoryOutput;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\GraphSubstrate;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\BinaryContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;
use Reli\Rmem\Explore\RmemModel;

class VizHtmlBuilderTest extends TestCase
{
    /** @var list<string> */
    private array $tmpFiles = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $p) {
            if (file_exists($p)) {
                @unlink($p);
            }
        }
    }

    // =================================================================
    // formatLink
    // =================================================================

    public function testFormatLinkArrayElements(): void
    {
        self::assertSame('[0]', VizHtmlBuilder::formatLink('0', 'array_elements'));
        self::assertSame('[foo_key]', VizHtmlBuilder::formatLink('foo_key', 'array_elements'));
    }

    public function testFormatLinkObjectProperties(): void
    {
        self::assertSame('->name', VizHtmlBuilder::formatLink('name', 'object_properties'));
    }

    public function testFormatLinkClassTable(): void
    {
        self::assertSame('class App\\Foo', VizHtmlBuilder::formatLink('App\\Foo', 'class_table'));
    }

    public function testFormatLinkFunctionTable(): void
    {
        self::assertSame('fn do_stuff', VizHtmlBuilder::formatLink('do_stuff', 'function_table'));
    }

    public function testFormatLinkDefault(): void
    {
        self::assertSame('random_link', VizHtmlBuilder::formatLink('random_link', 'something_else'));
        self::assertSame('random_link', VizHtmlBuilder::formatLink('random_link', null));
    }

    public function testFormatLinkEmptyOrNull(): void
    {
        self::assertSame('', VizHtmlBuilder::formatLink(null, 'array_elements'));
        self::assertSame('', VizHtmlBuilder::formatLink('', 'array_elements'));
    }

    public function testFormatLinkFrameLabelSubstitution(): void
    {
        [, , $model] = $this->buildCallFrameFixture();
        // Node 1 is the CallFrameContext carrying attributes
        // function_name='<main>', lineno='28'.
        self::assertSame('<main>:28', VizHtmlBuilder::formatLink('0', 'call_frames', 1, $model));
        // No substitution without model / nodeId
        self::assertSame('0', VizHtmlBuilder::formatLink('0', 'call_frames'));
    }

    // =================================================================
    // Subgraph extraction
    // =================================================================

    public function testBuildSubgraphSeedsAndAncestors(): void
    {
        [$substrate, , $model] = $this->buildSimpleChainFixture();
        [$nodes, $treeEdges, $refEdges] = VizHtmlBuilder::buildSubgraph(
            $model,
            $substrate,
            top: 10,
            depth: 0,
            allEdges: false,
        );

        $ids = array_map(fn (array $n) => $n['id'], $nodes);
        // Every leaf + its ancestor chain is in the subgraph even with
        // depth=0, because the ancestor walk is unconditional.
        self::assertContains(4, $ids, 'leaf StringContext reached');
        self::assertContains(3, $ids, 'ArrayElement wrapper reached');
        self::assertContains(2, $ids, 'ObjectPropertiesContext reached');
        self::assertContains(1, $ids, 'outer Object reached');
        self::assertSame([], $refEdges);
        self::assertNotSame([], $treeEdges);
    }

    public function testBuildSubgraphStructuralFreeHopReachesArrayElements(): void
    {
        [$substrate, , $model] = $this->buildObjectWithScalarArrayFixture();
        [$nodes] = VizHtmlBuilder::buildSubgraph(
            $model,
            $substrate,
            top: 1,      // only the Object is a seed
            depth: 1,    // one meaningful hop
            allEdges: false,
        );

        $ids = array_map(fn (array $n) => $n['id'], $nodes);
        // Free hops: object_properties (link) + ArrayHeaderContext (type)
        // + array_elements (link) + value (link) should all be transparent,
        // letting depth=1 reach individual ArrayElements + ScalarValues.
        self::assertContains(5, $ids, 'ArrayElement not reached');
        self::assertContains(6, $ids, 'ScalarValueContext not reached');
        self::assertContains(7, $ids, 'ArrayElement (second) not reached');
    }

    public function testBuildSubgraphAllEdgesIncludesReferenceEdges(): void
    {
        [$substrate, , $model] = $this->buildSimpleChainFixture(withBackref: true);
        [, , $refEdges] = VizHtmlBuilder::buildSubgraph(
            $model,
            $substrate,
            top: 500,
            depth: 1,
            allEdges: true,
        );
        self::assertNotSame([], $refEdges, 'ref edges should appear when allEdges=true');
    }

    public function testBuildSubgraphExcludesReferenceEdgesByDefault(): void
    {
        [$substrate, , $model] = $this->buildSimpleChainFixture(withBackref: true);
        [, , $refEdges] = VizHtmlBuilder::buildSubgraph(
            $model,
            $substrate,
            top: 500,
            depth: 1,
            allEdges: false,
        );
        self::assertSame([], $refEdges);
    }

    public function testBuildSubgraphHonoursMaxNodesCap(): void
    {
        // Seeds themselves are not capped (they're added before the
        // cap loop), but the ancestor walk and downward BFS respect
        // maxNodes. A chain with a deep ancestor list shows the cap
        // kicking in: a tight cap produces fewer nodes than a loose one.
        [$substrate, , $model] = $this->buildSimpleChainFixture();
        [$tight] = VizHtmlBuilder::buildSubgraph(
            $model,
            $substrate,
            top: 1,
            depth: 1,
            allEdges: false,
            maxNodes: 2,
        );
        [$loose] = VizHtmlBuilder::buildSubgraph(
            $model,
            $substrate,
            top: 1,
            depth: 1,
            allEdges: false,
            maxNodes: 1000,
        );
        self::assertLessThan(count($loose), count($tight));
    }

    public function testBuildSubgraphPerParentCapStratifiesAcrossObjectsAndScalars(): void
    {
        // Wide array: 20 object children (retained>0) + 30 scalar children (retained=0).
        // With max-children 8 the top-K pick must NOT drop every scalar —
        // stratified sampling keeps half the slots for the tail.
        [$substrate, , $model] = $this->buildWideFanOutFixture(
            objectChildren: 20,
            scalarChildren: 30,
        );
        [$nodes] = VizHtmlBuilder::buildSubgraph(
            $model,
            $substrate,
            top: 1,
            depth: 1,
            allEdges: false,
            maxNodes: 10000,
            maxChildrenPerNode: 8,
        );

        $types = array_count_values(array_map(fn (array $n) => $n['type'], $nodes));
        // At least some of each type should survive the cap.
        self::assertGreaterThan(0, $types['ObjectContext'] ?? 0);
        self::assertGreaterThan(0, $types['ArrayElementContext'] ?? 0);
    }

    // =================================================================
    // Node payload shape (formatLink routing + paths)
    // =================================================================

    public function testBuildPromotesClassTableLabelForClassContext(): void
    {
        [$substrate, , $model] = $this->buildClassTableFixture();
        [$nodes] = VizHtmlBuilder::buildSubgraph(
            $model,
            $substrate,
            top: 10,
            depth: 1,
            allEdges: false,
        );
        $byId = [];
        foreach ($nodes as $n) {
            $byId[$n['id']] = $n;
        }
        self::assertSame('class App\\Foo', $byId[2]['label']);
        self::assertSame('class App\\Foo', $byId[2]['link_display']);
    }

    public function testBuildPromotesArrayElementKeyInLabel(): void
    {
        [$substrate, , $model] = $this->buildObjectWithScalarArrayFixture();
        [$nodes] = VizHtmlBuilder::buildSubgraph(
            $model,
            $substrate,
            top: 10,
            depth: 1,
            allEdges: false,
        );
        $byId = [];
        foreach ($nodes as $n) {
            $byId[$n['id']] = $n;
        }
        // ArrayElement node id=5 sits under array_elements with link='0'.
        self::assertStringStartsWith('[0]', $byId[5]['label']);
        self::assertSame('[0]', $byId[5]['link_display']);
    }

    public function testRootLinkOfReturnsTopmostLinkName(): void
    {
        [$substrate] = $this->buildClassTableFixture();
        // Node 2 is "class App\\Foo" under node 1 (class_table) under
        // node 0 (root_entry). rootLinkOf should stop at class_table
        // because its parent's link is root_entry.
        self::assertSame('class_table', VizHtmlBuilder::rootLinkOf($substrate, 2));
    }

    public function testPathStepsIncludeNodeIdsAndFormattedLabels(): void
    {
        [$substrate, , $model] = $this->buildObjectWithScalarArrayFixture();
        $steps = VizHtmlBuilder::pathStepsOf($substrate, $model, 5);
        // Walks root-first; the final step corresponds to the element itself.
        self::assertNotEmpty($steps);
        $last = end($steps);
        self::assertSame(5, $last['node_id']);
        self::assertSame('[0]', $last['label']);
    }

    public function testPathOfUsesPhpSyntaxFormatter(): void
    {
        [$substrate, , $model] = $this->buildCallFrameFixture();
        // Leaf is ScalarValueContext under "messages" variable in <main>:28.
        $path = VizHtmlBuilder::pathOf($substrate, $model, 6);
        self::assertCount(1, $path);
        // PHP-syntax collapser should fold local_variables / symbol_table /
        // array_elements / value and surface the frame label.
        self::assertStringContainsString('<main>:28', $path[0]);
        self::assertStringContainsString('$messages', $path[0]);
    }

    // =================================================================
    // HTML rendering
    // =================================================================

    public function testBuildEmitsHtmlWithEmbeddedData(): void
    {
        [, $path, ] = $this->buildSimpleChainFixture();
        $this->tmpFiles[] = $path;
        [$substrate, $model] = $this->loadSubstrate($path);

        $html = VizHtmlBuilder::build(
            $model,
            $substrate,
            $path,
            top: 10,
            depth: 1,
            allEdges: false,
            liveMode: false,
        );

        self::assertStringContainsString('<!doctype html>', $html);
        self::assertStringContainsString('const DATA = {', $html);
        self::assertStringContainsString('"source":"' . basename($path) . '"', $html);
        // Static viz: live flag stays false in the generated template.
        self::assertMatchesRegularExpression('/const LIVE\s*=\s*false/', $html);
    }

    public function testBuildEmitsLiveFlagWhenLiveModeTrue(): void
    {
        [, $path, ] = $this->buildSimpleChainFixture();
        $this->tmpFiles[] = $path;
        [$substrate, $model] = $this->loadSubstrate($path);

        $html = VizHtmlBuilder::build(
            $model,
            $substrate,
            $path,
            top: 10,
            depth: 1,
            allEdges: false,
            liveMode: true,
            extraPayload: ['live' => ['events_url' => '/api/events']],
        );

        self::assertMatchesRegularExpression('/const LIVE\s*=\s*true/', $html);
        self::assertStringContainsString('"events_url":"/api/events"', $html);
    }

    // =================================================================
    // Fixture builders
    // =================================================================

    /**
     * @return array{0: GraphSubstrate, 1: string, 2: RmemModel}
     */
    private function buildSimpleChainFixture(bool $withBackref = false): array
    {
        $path = $this->newTmpRmem('simple_chain');
        $sink = new BinaryContextTreeSink(batch_size: 100);
        $sink->emitNode(0, null, 'root_entry', 'TopReferenceContext', [], []);
        $sink->emitNode(
            1,
            0,
            'holder',
            'ObjectContext',
            [new ZendObjectMemoryLocation(0x1000, 100, 1, 7, 'App\\Holder')],
            [],
        );
        $sink->emitNode(2, 1, 'object_properties', 'ObjectPropertiesContext', [], []);
        $sink->emitNode(
            3,
            2,
            'msg',
            'ArrayElementContext',
            [],
            [],
        );
        $sink->emitNode(
            4,
            3,
            'value',
            'StringContext',
            [new ZendStringMemoryLocation(0x2000, 48, 1, 6, 'hello')],
            [],
        );
        if ($withBackref) {
            $sink->emitReference(reference_node_id: 4, parent_node_id: 1, link_name: 'backref');
        }
        (new BinaryMemoryOutput($path))->finalizeStreaming($sink, [
            ['zend_mm_heap_usage' => '148', 'php_version' => '8.2.0'],
        ]);
        [$substrate, $model] = $this->loadSubstrate($path);
        return [$substrate, $path, $model];
    }

    /**
     * Object whose "items" property is an Array of 2 scalars.
     * Exercises the structural free-hop chain:
     *   Object → ObjectPropertiesContext → ArrayHeaderContext
     *   → ArrayElementsContext → ArrayElementContext → ScalarValueContext
     *
     * @return array{0: GraphSubstrate, 1: string, 2: RmemModel}
     */
    private function buildObjectWithScalarArrayFixture(): array
    {
        $path = $this->newTmpRmem('object_scalar_array');
        $sink = new BinaryContextTreeSink(batch_size: 100);
        $sink->emitNode(0, null, 'root_entry', 'TopReferenceContext', [], []);
        $sink->emitNode(
            1,
            0,
            'holder',
            'ObjectContext',
            [new ZendObjectMemoryLocation(0x1000, 100, 1, 7, 'App\\Holder')],
            [],
        );
        $sink->emitNode(2, 1, 'object_properties', 'ObjectPropertiesContext', [], []);
        $sink->emitNode(
            3,
            2,
            'items',
            'ArrayHeaderContext',
            [new ZendArrayMemoryLocation(0x2000, 56, 1, 7)],
            [],
        );
        $sink->emitNode(
            4,
            3,
            'array_elements',
            'ArrayElementsContext',
            [new ZendArrayMemoryLocation(0x2100, 128, 1, 7)],
            [],
        );
        $sink->emitNode(5, 4, '0', 'ArrayElementContext', [], []);
        $sink->emitNode(6, 5, 'value', 'ScalarValueContext', [], []);
        $sink->emitNode(7, 4, '1', 'ArrayElementContext', [], []);
        $sink->emitNode(8, 7, 'value', 'ScalarValueContext', [], []);
        (new BinaryMemoryOutput($path))->finalizeStreaming($sink, [
            ['zend_mm_heap_usage' => '284', 'php_version' => '8.2.0'],
        ]);
        [$substrate, $model] = $this->loadSubstrate($path);
        return [$substrate, $path, $model];
    }

    /**
     * class_table with one class definition (ClassContext id=2 under link
     * "App\\Foo"). Used for label promotion + rootLinkOf tests.
     *
     * @return array{0: GraphSubstrate, 1: string, 2: RmemModel}
     */
    private function buildClassTableFixture(): array
    {
        $path = $this->newTmpRmem('class_table');
        $sink = new BinaryContextTreeSink(batch_size: 100);
        $sink->emitNode(0, null, 'root_entry', 'TopReferenceContext', [], []);
        $sink->emitNode(
            1,
            0,
            'class_table',
            'ArrayHeaderContext',
            [new ZendArrayMemoryLocation(0x1000, 56, 1, 7)],
            [],
        );
        $sink->emitNode(
            2,
            1,
            'App\\Foo',
            'ClassContext',
            [new ZendArrayMemoryLocation(0x2000, 200, 1, 7)],
            [],
        );
        (new BinaryMemoryOutput($path))->finalizeStreaming($sink, [
            ['zend_mm_heap_usage' => '256', 'php_version' => '8.2.0'],
        ]);
        [$substrate, $model] = $this->loadSubstrate($path);
        return [$substrate, $path, $model];
    }

    /**
     * CallFrame with local variable '$messages' referencing an array with
     * one scalar element. Used for frame-label substitution in pathOf.
     *
     * @return array{0: GraphSubstrate, 1: string, 2: RmemModel}
     */
    private function buildCallFrameFixture(): array
    {
        $path = $this->newTmpRmem('call_frame');
        $sink = new BinaryContextTreeSink(batch_size: 100);
        $sink->emitNode(0, null, 'call_frames', 'CallFramesContext', [], []);
        $sink->emitNode(
            1,
            0,
            '0',
            'CallFrameContext',
            [new ZendArrayMemoryLocation(0x1000, 100, 1, 7)],
            ['function_name' => '<main>', 'lineno' => '28'],
        );
        $sink->emitNode(2, 1, 'local_variables', 'CallFrameVariableTableContext', [], []);
        $sink->emitNode(
            3,
            2,
            'symbol_table',
            'ArrayHeaderContext',
            [new ZendArrayMemoryLocation(0x2000, 56, 1, 7)],
            [],
        );
        $sink->emitNode(4, 3, 'array_elements', 'ArrayElementsContext', [], []);
        $sink->emitNode(5, 4, 'messages', 'ArrayElementContext', [], []);
        $sink->emitNode(6, 5, 'value', 'ScalarValueContext', [], []);
        (new BinaryMemoryOutput($path))->finalizeStreaming($sink, [
            ['zend_mm_heap_usage' => '156', 'php_version' => '8.2.0'],
        ]);
        [$substrate, $model] = $this->loadSubstrate($path);
        // ensure attribute index is loaded so frameLabels populate
        $model->ensureLocationInfoLoaded();
        // Rebuild label cache by calling getFrameLabel once
        // (frameLabels is populated eagerly on model construction).
        return [$substrate, $path, $model];
    }

    /**
     * ArrayElementsContext with a wide fan-out: N object-valued elements
     * (each retained>0) and M scalar-valued elements (retained=0). Used
     * to exercise the per-parent cap + stratified sample.
     *
     * @return array{0: GraphSubstrate, 1: string, 2: RmemModel}
     */
    private function buildWideFanOutFixture(int $objectChildren = 5, int $scalarChildren = 5): array
    {
        $path = $this->newTmpRmem('wide_fanout');
        $sink = new BinaryContextTreeSink(batch_size: 100);
        $sink->emitNode(0, null, 'root_entry', 'TopReferenceContext', [], []);
        $sink->emitNode(
            1,
            0,
            'theArr',
            'ArrayHeaderContext',
            [new ZendArrayMemoryLocation(0x1000, 56, 1, 7)],
            [],
        );
        $sink->emitNode(
            2,
            1,
            'array_elements',
            'ArrayElementsContext',
            [new ZendArrayMemoryLocation(0x2000, 128, 1, 7)],
            [],
        );
        $nextId = 3;
        // Object-valued elements: ArrayElement → ObjectContext(sized)
        for ($i = 0; $i < $objectChildren; $i++) {
            $elementId = $nextId++;
            $sink->emitNode($elementId, 2, (string)$i, 'ArrayElementContext', [], []);
            $sink->emitNode(
                $nextId++,
                $elementId,
                'value',
                'ObjectContext',
                [new ZendObjectMemoryLocation(0x10000 + $i * 0x100, 80, 1, 7, 'App\\Bag')],
                [],
            );
        }
        // Scalar-valued elements: ArrayElement → ScalarValueContext (retained=0)
        for ($j = 0; $j < $scalarChildren; $j++) {
            $elementId = $nextId++;
            $sink->emitNode($elementId, 2, (string)($objectChildren + $j), 'ArrayElementContext', [], []);
            $sink->emitNode($nextId++, $elementId, 'value', 'ScalarValueContext', [], []);
        }
        (new BinaryMemoryOutput($path))->finalizeStreaming($sink, [
            ['zend_mm_heap_usage' => '1000', 'php_version' => '8.2.0'],
        ]);
        [$substrate, $model] = $this->loadSubstrate($path);
        return [$substrate, $path, $model];
    }

    private function newTmpRmem(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), "reli_viz_{$prefix}_");
        self::assertNotFalse($path);
        $path .= '.rmem';
        $this->tmpFiles[] = $path;
        return $path;
    }

    /** @return array{0: GraphSubstrate, 1: RmemModel} */
    private function loadSubstrate(string $path): array
    {
        $reader = BinaryReader::open($path);
        $substrate = GraphSubstrate::createFromBinary($reader, forceFfiCsr: false, skipScc: true);
        $model = RmemModel::fromSubstrate($substrate, $reader);
        return [$substrate, $model];
    }
}
