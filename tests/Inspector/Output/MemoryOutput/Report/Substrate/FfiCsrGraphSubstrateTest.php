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

namespace Reli\Inspector\Output\MemoryOutput\Report\Substrate;

use Reli\BaseTestCase;
use Reli\Inspector\Output\MemoryOutput\MemoryAnalysisResult;
use Reli\Inspector\Output\MemoryOutput\PdoDriver\SqliteDriver;
use Reli\Inspector\Output\MemoryOutput\PdoMemoryOutput;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\EdgeStrength;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ReferenceContext;
use Reli\Lib\Process\MemoryLocation;

class FfiCsrGraphSubstrateTest extends BaseTestCase
{
    private string $db_path;

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('ffi')) {
            $this->markTestSkipped('FFI extension not available');
        }
        $this->db_path = tempnam(sys_get_temp_dir(), 'reli_ffi_csr_test_') . '.db';
    }

    protected function tearDown(): void
    {
        @unlink($this->db_path);
        parent::tearDown();
    }

    public function testLoadFromDbBuildsNodeSizes(): void
    {
        $leaf = $this->createMockContext('leaf', [], [
            new ZendObjectMemoryLocation(0x2000, 128, 1, 7, 'App\\Leaf'),
        ]);
        $top = $this->createMockContext('top', ['root' => $leaf], []);
        $this->buildDb($top);

        $db = $this->openDb();
        $substrate = FfiCsrGraphSubstrate::loadFromDb($db, 1);

        $this->assertInstanceOf(FfiCsrGraphSubstrate::class, $substrate);
        // Check via accessor method
        $found128 = false;
        foreach ($substrate->iterateNodeSizes() as $size) {
            if ($size === 128) {
                $found128 = true;
            }
        }
        $this->assertTrue($found128);
    }

    public function testSubtreeSizesComputed(): void
    {
        $child = $this->createMockContext('child', [], [
            new ZendObjectMemoryLocation(0x2000, 200, 1, 7, 'App\\Child'),
        ]);
        $parent = $this->createMockContext('parent', ['child_link' => $child], [
            new ZendObjectMemoryLocation(0x1000, 100, 1, 7, 'App\\Parent'),
        ]);
        $top = $this->createMockContext('top', ['root' => $parent], []);
        $this->buildDb($top);

        $db = $this->openDb();
        $substrate = FfiCsrGraphSubstrate::loadFromDb($db, 1);

        $this->assertTrue($substrate->hasSubtreeSizes());

        // Parent's subtree should include child: 100 + 200 = 300
        $max_subtree = 0;
        foreach ($substrate->iterateSubtreeSizes() as $size) {
            $max_subtree = max($max_subtree, $size);
        }
        $this->assertSame(300, $max_subtree);
    }

    public function testRootsIdentified(): void
    {
        $child = $this->createMockContext('child', [], [
            new ZendObjectMemoryLocation(0x2000, 64, 1, 7, 'App\\Node'),
        ]);
        $top = $this->createMockContext('top', ['root' => $child], []);
        $this->buildDb($top);

        $db = $this->openDb();
        $substrate = FfiCsrGraphSubstrate::loadFromDb($db, 1);

        $this->assertNotEmpty($substrate->getRoots());
    }

    public function testSccDetectsNoCyclesInTree(): void
    {
        $leaf = $this->createMockContext('leaf', [], [
            new ZendObjectMemoryLocation(0x2000, 64, 1, 7, 'App\\Node'),
        ]);
        $top = $this->createMockContext('top', ['root' => $leaf], []);
        $this->buildDb($top);

        $db = $this->openDb();
        $substrate = FfiCsrGraphSubstrate::loadFromDb($db, 1);

        $this->assertEmpty($substrate->getSccProfiles());
        $sccEntries = iterator_to_array($substrate->iterateNodeToScc());
        $this->assertEmpty($sccEntries);
    }

    public function testEdgeCountTracked(): void
    {
        $child = $this->createMockContext('child', [], [
            new ZendObjectMemoryLocation(0x2000, 64, 1, 7, 'App\\Node'),
        ]);
        $top = $this->createMockContext('top', ['root' => $child], []);
        $this->buildDb($top);

        $db = $this->openDb();
        $substrate = FfiCsrGraphSubstrate::loadFromDb($db, 1);

        $this->assertGreaterThan(0, $substrate->getEdgeCount());
    }

    public function testNodeClassesPopulated(): void
    {
        $child = $this->createMockContext('child', [], [
            new ZendObjectMemoryLocation(0x2000, 64, 1, 7, 'App\\MyClass'),
        ]);
        $top = $this->createMockContext('top', ['root' => $child], []);
        $this->buildDb($top);

        $db = $this->openDb();
        $substrate = FfiCsrGraphSubstrate::loadFromDb($db, 1);

        $found = false;
        foreach ($substrate->iterateNodeClasses() as $cls) {
            if ($cls === 'App\\MyClass') {
                $found = true;
            }
        }
        $this->assertTrue($found);
    }

    public function testGetChildrenReturnsCorrectChildren(): void
    {
        $grandchild = $this->createMockContext('grandchild', [], [
            new ZendObjectMemoryLocation(0x3000, 32, 1, 7, 'App\\GC'),
        ]);
        $child = $this->createMockContext('child', ['gc_link' => $grandchild], [
            new ZendObjectMemoryLocation(0x2000, 64, 1, 7, 'App\\Child'),
        ]);
        $parent = $this->createMockContext('parent', ['child_link' => $child], [
            new ZendObjectMemoryLocation(0x1000, 128, 1, 7, 'App\\Parent'),
        ]);
        $top = $this->createMockContext('top', ['root' => $parent], []);
        $this->buildDb($top);

        $db = $this->openDb();
        $substrate = FfiCsrGraphSubstrate::loadFromDb($db, 1);

        // Find the parent node and check it has children
        $parent_node_id = null;
        foreach ($substrate->iterateNodeClasses() as $nid => $cls) {
            if ($cls === 'App\\Parent') {
                $parent_node_id = $nid;
                break;
            }
        }
        $this->assertNotNull($parent_node_id);
        $children = $substrate->getChildren($parent_node_id);
        $this->assertNotEmpty($children);
    }

    public function testGetNodeSizeReturnsZeroForUnknownNode(): void
    {
        $child = $this->createMockContext('child', [], [
            new ZendObjectMemoryLocation(0x2000, 64, 1, 7, 'App\\Node'),
        ]);
        $top = $this->createMockContext('top', ['root' => $child], []);
        $this->buildDb($top);

        $db = $this->openDb();
        $substrate = FfiCsrGraphSubstrate::loadFromDb($db, 1);

        $this->assertSame(0, $substrate->getNodeSize(999999999));
    }

    public function testGetNodeSizesSumMatchesIteration(): void
    {
        $child = $this->createMockContext('child', [], [
            new ZendObjectMemoryLocation(0x2000, 200, 1, 7, 'App\\Child'),
        ]);
        $parent = $this->createMockContext('parent', ['child_link' => $child], [
            new ZendObjectMemoryLocation(0x1000, 100, 1, 7, 'App\\Parent'),
        ]);
        $top = $this->createMockContext('top', ['root' => $parent], []);
        $this->buildDb($top);

        $db = $this->openDb();
        $substrate = FfiCsrGraphSubstrate::loadFromDb($db, 1);

        $iterSum = 0;
        foreach ($substrate->iterateNodeSizes() as $size) {
            $iterSum += $size;
        }
        $this->assertSame($iterSum, $substrate->getNodeSizesSum());
    }

    public function testConsistencyWithPhpArrayVersion(): void
    {
        $child1 = $this->createMockContext('child1', [], [
            new ZendObjectMemoryLocation(0x3000, 50, 1, 7, 'App\\A'),
        ]);
        $child2 = $this->createMockContext('child2', [], [
            new ZendObjectMemoryLocation(0x4000, 75, 1, 7, 'App\\B'),
        ]);
        $parent = $this->createMockContext('parent', [
            'link_a' => $child1,
            'link_b' => $child2,
        ], [
            new ZendObjectMemoryLocation(0x1000, 100, 1, 7, 'App\\Parent'),
        ]);
        $top = $this->createMockContext('top', ['root' => $parent], []);
        $this->buildDb($top);

        $db = $this->openDb();
        $php = GraphSubstrate::loadFromDb($db, 1);
        $ffi = FfiCsrGraphSubstrate::loadFromDb($db, 1);

        // Compare roots
        $this->assertEqualsCanonicalizing($php->getRoots(), $ffi->getRoots());

        // Compare edge counts
        $this->assertSame($php->getEdgeCount(), $ffi->getEdgeCount());

        // Compare node sizes sum
        $this->assertSame($php->getNodeSizesSum(), $ffi->getNodeSizesSum());

        // Compare subtree sizes for all nodes
        foreach ($php->iterateSubtreeSizes() as $nid => $size) {
            $this->assertSame(
                $size,
                $ffi->getSubtreeSize($nid),
                "Subtree size mismatch for node {$nid}"
            );
        }

        // Compare SCC profiles count
        $this->assertSame(
            count($php->getSccProfiles()),
            count($ffi->getSccProfiles())
        );
    }

    public function testCreateFromDbSelectsCorrectImplementation(): void
    {
        $child = $this->createMockContext('child', [], [
            new ZendObjectMemoryLocation(0x2000, 64, 1, 7, 'App\\Node'),
        ]);
        $top = $this->createMockContext('top', ['root' => $child], []);
        $this->buildDb($top);

        $db = $this->openDb();
        // Small graph should use PHP array version
        $substrate = GraphSubstrate::createFromDb($db, 1);
        $this->assertInstanceOf(GraphSubstrate::class, $substrate);
        // With small data, it should NOT be FfiCsr
        $this->assertNotInstanceOf(FfiCsrGraphSubstrate::class, $substrate);
    }

    // ---- Helpers ----

    private function openDb(): \PDO
    {
        $db = new \PDO('sqlite:' . $this->db_path);
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $db;
    }

    private function buildDb(ReferenceContext $top): void
    {
        $result = new MemoryAnalysisResult([], $top);
        $output = new PdoMemoryOutput(new SqliteDriver($this->db_path));
        $output->output($result);
    }

    /**
     * @param array<string, ReferenceContext> $links
     * @param list<MemoryLocation> $locations
     * @param array<string, mixed> $attributes
     */
    private function createMockContext(
        string $name,
        array $links,
        array $locations,
        array $attributes = [],
    ): ReferenceContext {
        $context = \Mockery::mock(ReferenceContext::class);
        $context->allows('getName')->andReturns($name);
        $context->allows('getLinks')->andReturns($links);
        $context->allows('getLocations')->andReturns($locations);
        $context->allows('getContexts')->andReturns($attributes);
        $context->allows('releaseLinks');
        $context->allows('getLinkStrength')->andReturns(EdgeStrength::Strong);
        return $context;
    }
}
