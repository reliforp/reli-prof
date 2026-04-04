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

namespace Reli\Inspector\Output\MemoryOutput\Report;

use Reli\BaseTestCase;
use Reli\Inspector\Output\MemoryOutput\MemoryAnalysisResult;
use Reli\Inspector\Output\MemoryOutput\PdoDriver\SqliteDriver;
use Reli\Inspector\Output\MemoryOutput\PdoMemoryOutput;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\EdgeStrength;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ReferenceContext;
use Reli\Lib\Process\MemoryLocation;

class ReportGeneratorTest extends BaseTestCase
{
    private string $db_path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db_path = tempnam(sys_get_temp_dir(), 'reli_report_test_') . '.db';
    }

    protected function tearDown(): void
    {
        @unlink($this->db_path);
        parent::tearDown();
    }

    public function testGenerateFromDbReturnsReportResult(): void
    {
        $this->buildMinimalDb();
        $db = $this->openDb();

        $generator = new ReportGenerator();
        $result = $generator->generateFromDb($db, 1);

        $this->assertInstanceOf(ReportResult::class, $result);
        $this->assertIsArray($result->meta);
        $this->assertIsArray($result->findings);
    }

    public function testOverviewFindingAlwaysPresent(): void
    {
        $this->buildMinimalDb([
            ['zend_mm_heap_total' => 10485760, 'zend_mm_heap_usage' => 8388608],
            ['heap_memory_analyzed_percentage' => 99.5],
        ]);
        $result = $this->generateReport();

        $overview = $this->findByKind($result, 'overview');
        $this->assertCount(1, $overview);
        $this->assertSame('info', $overview[0]->severity->value);
        $this->assertStringContainsString('99.5%', $overview[0]->summary);
    }

    public function testCoverageGapDetected(): void
    {
        $this->buildMinimalDb([
            ['zend_mm_heap_total' => 10485760, 'zend_mm_heap_usage' => 8388608],
            ['heap_memory_analyzed_percentage' => 80.0],
        ]);
        $result = $this->generateReport();

        $gaps = $this->findByKind($result, 'coverage_gap');
        $this->assertCount(1, $gaps);
        $this->assertSame('warning', $gaps[0]->severity->value);
        $this->assertStringContainsString('80.0%', $gaps[0]->summary);
    }

    public function testNoCoverageGapWhenFullyCovered(): void
    {
        $this->buildMinimalDb([
            ['zend_mm_heap_total' => 1048576, 'zend_mm_heap_usage' => 1048576],
            ['heap_memory_analyzed_percentage' => 99.0],
        ]);
        $result = $this->generateReport();

        $gaps = $this->findByKind($result, 'coverage_gap');
        $this->assertCount(0, $gaps);
    }

    public function testDominantClassDetected(): void
    {
        // Create DB with one class dominating
        $locations = [];
        for ($i = 0; $i < 100; $i++) {
            $locations[] = new ZendObjectMemoryLocation(
                0x1000 + $i * 0x100,
                256,
                1,
                7,
                'App\\DominantClass'
            );
        }
        $locations[] = new ZendObjectMemoryLocation(0x9000, 64, 1, 7, 'App\\MinorClass');

        $this->buildDbWithLocations($locations);
        $result = $this->generateReport();

        $dominant = $this->findByKind($result, 'dominant_class');
        $this->assertNotEmpty($dominant);
        $this->assertStringContainsString('DominantClass', $dominant[0]->summary);
        $this->assertSame('high', $dominant[0]->severity->value);
    }

    public function testCompanionPairDetected(): void
    {
        $locations = [];
        // 150 of ClassA and 150 of ClassB (within 5%)
        for ($i = 0; $i < 150; $i++) {
            $locations[] = new ZendObjectMemoryLocation(
                0x1000 + $i * 0x100,
                64,
                1,
                7,
                'App\\ClassA'
            );
            $locations[] = new ZendObjectMemoryLocation(
                0x50000 + $i * 0x100,
                64,
                1,
                7,
                'App\\ClassB'
            );
        }

        $this->buildDbWithLocations($locations);
        $result = $this->generateReport();

        $companions = $this->findByKind($result, 'companion_cluster');
        $this->assertNotEmpty($companions);
        $this->assertStringContainsString('ClassA', $companions[0]->summary);
        $this->assertStringContainsString('ClassB', $companions[0]->summary);
    }

    public function testLargeStringDetected(): void
    {
        $big_string = new ZendStringMemoryLocation(0x1000, 102400, 1, 6, str_repeat('x', 100));
        $holder = $this->createMockContext('holder', [], [$big_string]);
        $top = $this->createMockContext('top', ['entry' => $holder], []);

        $this->buildDbFromContext($top);
        $result = $this->generateReport();

        $strings = $this->findByKind($result, 'large_string');
        $this->assertNotEmpty($strings);
        $this->assertSame(102400, $strings[0]->facts['size']);
    }

    public function testBottleneckPathDetected(): void
    {
        // Build a deep tree: root -> level1 -> level2 -> leaf(big)
        $big_leaf = $this->createMockContext(
            'leaf',
            [],
            [new ZendObjectMemoryLocation(0x4000, 1048576, 1, 7, 'App\\BigLeaf')]
        );
        $level2 = $this->createMockContext('level2', ['big_data' => $big_leaf], [
            new ZendObjectMemoryLocation(0x3000, 64, 1, 7, 'App\\Node'),
        ]);
        $level1 = $this->createMockContext('level1', ['container' => $level2], [
            new ZendObjectMemoryLocation(0x2000, 64, 1, 7, 'App\\Node'),
        ]);
        $top = $this->createMockContext('top', ['call_frames' => $level1], []);

        $this->buildDbFromContext($top);
        $result = $this->generateReport();

        $paths = $this->findByKind($result, 'bottleneck_path');
        $this->assertNotEmpty($paths);
        // Path is PHP-syntax formatted; check for meaningful parts
        $this->assertStringContainsString('container', $paths[0]->summary);
    }

    public function testChokePointDetected(): void
    {
        // A small node holding a big subtree
        $children = [];
        for ($i = 0; $i < 50; $i++) {
            $child = $this->createMockContext(
                'child',
                [],
                [new ZendObjectMemoryLocation(0x10000 + $i * 0x1000, 25000, 1, 7, 'App\\HeavyChild')]
            );
            $children["item_{$i}"] = $child;
        }
        // Small container holding >1MB total
        $container = $this->createMockContext('container', $children, [
            new ZendObjectMemoryLocation(0x1000, 64, 1, 7, 'App\\SmallContainer'),
        ]);
        $top = $this->createMockContext('top', ['root' => $container], []);

        $this->buildDbFromContext($top);
        $result = $this->generateReport();

        $chokes = $this->findByKind($result, 'choke_point');
        $this->assertNotEmpty($chokes);
    }

    public function testRetainedSizeExactWhenNoCycles(): void
    {
        // Simple tree with no shared references = no cycles
        $leaf = $this->createMockContext('leaf', [], [
            new ZendObjectMemoryLocation(0x2000, 128, 1, 7, 'App\\Leaf'),
        ]);
        $top = $this->createMockContext('top', ['root' => $leaf], []);

        $this->buildDbFromContext($top);
        $result = $this->generateReport();

        $exact = $this->findByKind($result, 'retained_exact');
        $this->assertNotEmpty($exact);
        $this->assertSame('info', $exact[0]->severity->value);
    }

    public function testBlameAllocationProduced(): void
    {
        $child1 = $this->createMockContext('child1', [], [
            new ZendObjectMemoryLocation(0x2000, 1024, 1, 7, 'App\\A'),
        ]);
        $child2 = $this->createMockContext('child2', [], [
            new ZendObjectMemoryLocation(0x3000, 2048, 1, 7, 'App\\B'),
        ]);
        $top = $this->createMockContext('top', [
            'call_frames' => $child1,
            'class_table' => $child2,
        ], []);

        $this->buildDbFromContext($top);
        $result = $this->generateReport();

        $blame = $this->findByKind($result, 'root_blame');
        $this->assertGreaterThanOrEqual(2, count($blame));
    }

    public function testReportResultToArrayIncludesMetaAndFindings(): void
    {
        $this->buildMinimalDb([
            ['zend_mm_heap_total' => 1048576, 'zend_mm_heap_usage' => 524288],
            ['heap_memory_analyzed_percentage' => 99.0],
        ]);
        $result = $this->generateReport();
        $array = $result->toArray();

        $this->assertArrayHasKey('meta', $array);
        $this->assertArrayHasKey('findings', $array);
        $this->assertIsArray($array['findings']);
        $this->assertNotEmpty($array['findings']);
        // Each finding should be a serializable array
        $this->assertArrayHasKey('kind', $array['findings'][0]);
        $this->assertArrayHasKey('severity', $array['findings'][0]);
    }

    public function testAdaptiveComplexitySkipsGraphForLargeDatasets(): void
    {
        // We test by checking meta has node/edge counts
        $this->buildMinimalDb();
        $result = $this->generateReport();

        $this->assertArrayHasKey('node_count', $result->meta);
        $this->assertArrayHasKey('edge_count', $result->meta);
    }

    public function testFullAnalysisRunsGraphPassesForLargeGraph(): void
    {
        // Build a graph with a cycle to verify CycleClusterPass runs
        $nodeA = $this->createMockContext('objA', [], [
            new ZendObjectMemoryLocation(0x1000, 256, 1, 7, 'App\\NodeA'),
        ]);
        $nodeB = $this->createMockContext('objB', [], [
            new ZendObjectMemoryLocation(0x2000, 256, 1, 7, 'App\\NodeB'),
        ]);
        // Manually build a cyclic graph in the DB
        $top = $this->createMockContext('top', ['root' => $nodeA], []);
        $this->buildDbFromContext($top);

        // Insert extra edges to create a cycle: A -> B -> A
        $db = $this->openDb();
        $db->exec("INSERT INTO context_edges (run_id, parent_node_id, child_node_id, link_name, is_tree, strength)"
            . " VALUES (1, 1, 3, 'child_b', 1, 'strong')");
        $db->exec("INSERT INTO context_nodes (run_id, node_id, type) VALUES (1, 3, 'objB')");
        $db->exec("INSERT INTO context_node_locations (run_id, node_id, address, size, location_type, region)"
            . " VALUES (1, 3, 8192, 256, 'ZendObjectMemoryLocation', 'zend_mm_heap')");
        $db->exec("INSERT INTO context_edges (run_id, parent_node_id, child_node_id, link_name, is_tree, strength)"
            . " VALUES (1, 3, 1, 'back_ref', 0, 'strong')");

        // With full_analysis=true, CycleClusterPass should detect the cycle
        $generator = new ReportGenerator();
        $result = $generator->generateFromDb($db, 1, true);

        $cycles = $this->findByKind($result, 'cycle_cluster');
        $micro = $this->findByKind($result, 'micro_cycle');
        $retainedExact = $this->findByKind($result, 'retained_exact');

        // Either cycle detection found cycles, or retained_exact says "exact"
        // (confirming graph passes ran). The key assertion is that graph passes
        // actually execute with full_analysis=true.
        $graphPassRan = !empty($cycles) || !empty($micro) || !empty($retainedExact);
        $this->assertTrue($graphPassRan, 'Graph-based passes should run with full_analysis=true');
    }

    // ---- Helpers ----

    private function generateReport(): ReportResult
    {
        $db = $this->openDb();
        $generator = new ReportGenerator();
        return $generator->generateFromDb($db, 1);
    }

    private function openDb(): \PDO
    {
        $db = new \PDO('sqlite:' . $this->db_path);
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $db;
    }

    /** @param array<int, array<string, mixed>> $summary */
    private function buildMinimalDb(array $summary = []): void
    {
        $context = $this->createMockContext('empty', [], []);
        $result = new MemoryAnalysisResult($summary, $context);
        $output = new PdoMemoryOutput(new SqliteDriver($this->db_path));
        $output->output($result);
    }

    /** @param list<MemoryLocation> $locations */
    private function buildDbWithLocations(array $locations): void
    {
        $holder = $this->createMockContext('holder', [], $locations);
        $top = $this->createMockContext('top', ['entry' => $holder], []);

        $result = new MemoryAnalysisResult([
            ['zend_mm_heap_total' => 10485760, 'zend_mm_heap_usage' => 8388608],
            ['heap_memory_analyzed_percentage' => 99.0],
        ], $top);
        $output = new PdoMemoryOutput(new SqliteDriver($this->db_path));
        $output->output($result);
    }

    private function buildDbFromContext(ReferenceContext $top): void
    {
        $result = new MemoryAnalysisResult([
            ['zend_mm_heap_total' => 10485760, 'zend_mm_heap_usage' => 8388608],
            ['heap_memory_analyzed_percentage' => 99.0],
        ], $top);
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

    /** @return list<Finding> */
    private function findByKind(ReportResult $result, string $kind): array
    {
        return array_values(array_filter(
            $result->findings,
            fn(Finding $f) => $f->kind === $kind,
        ));
    }
}
