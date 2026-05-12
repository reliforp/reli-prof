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

namespace Reli\Inspector\Output\MemoryOutput\Report\Pass;

use Reli\BaseTestCase;
use Reli\Inspector\Output\MemoryOutput\Report\Finding;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\GraphSubstrate;

class PropertyScalingPassTest extends BaseTestCase
{
    private string $db_path = '';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $path = tempnam(sys_get_temp_dir(), 'reli_property_scaling_test_');
        self::assertNotFalse($path);
        $this->db_path = $path . '.db';
    }

    #[\Override]
    protected function tearDown(): void
    {
        @unlink($this->db_path);
        parent::tearDown();
    }

    public function testFindingFiresWithoutHeapClampWhenWithinHeap(): void
    {
        $db = $this->createDirectDb();
        // 200 GraphNode instances, each with one large property (1024 B
        // each); total per_instance ≈ 204800 B. Heap clamp default = 0.
        $this->seedScalingScenario($db, 'App\\GraphNode', 200, 1024);

        $substrate = GraphSubstrate::loadFromDb($db, 1);
        $findings = (new PropertyScalingPass(
            $db,
            1,
            ['App\\GraphNode' => ['count' => 200, 'memory_usage' => 200 * 64]],
            $substrate,
        ))->analyze();

        $finding = $this->findFinding($findings, 'property_scaling', 'App\\GraphNode');
        $this->assertNotNull($finding);
        $this->assertSame(204800, $finding->facts['per_instance_total_bytes']);
        $this->assertSame(204800, $finding->impact_bytes);
        $this->assertArrayNotHasKey(
            'per_instance_total_bytes_unclamped',
            $finding->facts,
        );
    }

    public function testFindingUsesUnionSumForSharedSubtreeUnderRetained(): void
    {
        // T2.1: per-property `size` should be the **union** of tree-edge
        // subtrees rooted at the property's distinct child node_ids.
        // When N instances reach a shared subtree, the size reflects
        // that subtree once — not N times — so the headline
        // `per_instance_total_bytes` doesn't blow past the heap total
        // and no clamp is needed.
        //
        // Scenario: 200 GraphNode instances. Each instance has a
        // per-instance 32 B value-holder property, tree-edged to one
        // shared 4 KB subtree. Pre-T2.1 (Σ_i getSubtreeSize): every
        // holder's retained = 32 + 4096 = 4128, summed over 200 =
        // 825,600 B (~6× heap if heap is small). With union: each
        // holder counted once (200 × 32 = 6400) plus the shared
        // 4 KB counted once = 10,496.
        $db = $this->createDirectDb();
        $this->seedSharedSubtreeScenario($db, 'App\\GraphNode', 200);

        $substrate = GraphSubstrate::loadFromDb($db, 1);
        $findings = (new PropertyScalingPass(
            $db,
            1,
            ['App\\GraphNode' => ['count' => 200, 'memory_usage' => 200 * 64]],
            $substrate,
        ))->analyze();

        $finding = $this->findFinding($findings, 'property_scaling', 'App\\GraphNode');
        $this->assertNotNull($finding);
        $this->assertSame(200, $finding->facts['instance_count']);
        $this->assertSame(10496, $finding->facts['per_instance_total_bytes']);
        $this->assertArrayNotHasKey(
            'per_instance_total_bytes_unclamped',
            $finding->facts,
            'union sum is intrinsically bounded; no clamp metadata',
        );
        $this->assertStringNotContainsString('clamped', $finding->hypothesis);
    }

    private function seedSharedSubtreeScenario(
        \PDO $db,
        string $class_name,
        int $instance_count,
    ): void {
        $node_stmt = $db->prepare(
            'INSERT INTO context_node_locations'
            . ' (run_id, node_id, address, size, location_type, class_name, string_value)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $edge_stmt = $db->prepare(
            'INSERT INTO context_edges'
            . ' (run_id, parent_node_id, child_node_id, link_name, is_tree, strength)'
            . ' VALUES (?, ?, ?, ?, ?, ?)'
        );

        // The shared property value (4 KB string), tree-edged from a
        // shared anchor whose canonical_node_id makes 200 logical
        // pointers resolve to one node.
        $shared_value_id = 800000;
        $node_stmt->execute([
            1, $shared_value_id, 8_000_000, 4096,
            'ZendStringMemoryLocation', null, null,
        ]);

        for ($i = 0; $i < $instance_count; $i++) {
            $owner_id = 1000 + $i * 4;
            $props_id = $owner_id + 1;
            $value_id = $owner_id + 2; // per-instance property holder

            $node_stmt->execute([
                1, $owner_id, 1_000_000 + $i, 64,
                'ZendObjectMemoryLocation', $class_name, null,
            ]);
            // Per-instance value-holder node (32 B — small wrapper)
            // tree-edged via 'edges'. The substrate's getCanonical()
            // dedups them via canonical_node_id below so the property
            // resolves to a single shared seed across all instances.
            $node_stmt->execute([
                1, $value_id, 3_000_000 + $i, 32,
                'ZendStringMemoryLocation', null, null,
            ]);

            $edge_stmt->execute([1, null, $owner_id, "owner_{$i}", 1, 'strong']);
            $edge_stmt->execute([
                1, $owner_id, $props_id, 'object_properties', 1, 'strong',
            ]);
            $edge_stmt->execute([
                1, $props_id, $value_id, 'edges', 1, 'strong',
            ]);
            // Tree edge from each per-instance holder to the same shared
            // 4 KB value. Substrate walks getStrongChildren() so this
            // shared child is reachable from all 200 holder seeds.
            $edge_stmt->execute([
                1, $value_id, $shared_value_id, 'value', 1, 'strong',
            ]);
        }
    }

    /**
     * @param list<Finding> $findings
     */
    private function findFinding(
        array $findings,
        string $kind,
        string $summary_part,
    ): ?Finding {
        foreach ($findings as $finding) {
            if (
                $finding->kind === $kind
                && str_contains($finding->summary, $summary_part)
            ) {
                return $finding;
            }
        }

        return null;
    }

    private function seedScalingScenario(
        \PDO $db,
        string $class_name,
        int $instance_count,
        int $property_size,
    ): void {
        $node_stmt = $db->prepare(
            'INSERT INTO context_node_locations'
            . ' (run_id, node_id, address, size, location_type, class_name, string_value)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $edge_stmt = $db->prepare(
            'INSERT INTO context_edges'
            . ' (run_id, parent_node_id, child_node_id, link_name, is_tree, strength)'
            . ' VALUES (?, ?, ?, ?, ?, ?)'
        );

        for ($i = 0; $i < $instance_count; $i++) {
            $owner_node_id = 1000 + $i * 4;
            $properties_node_id = $owner_node_id + 1;
            $prop_value_node_id = $owner_node_id + 2;

            // dominant class instance (64 B shallow object location)
            $node_stmt->execute([
                1,
                $owner_node_id,
                1_000_000 + $i,
                64,
                'ZendObjectMemoryLocation',
                $class_name,
                null,
            ]);
            // Property value: a $property_size-byte string
            $node_stmt->execute([
                1,
                $prop_value_node_id,
                3_000_000 + $i,
                $property_size,
                'ZendStringMemoryLocation',
                null,
                null,
            ]);

            $edge_stmt->execute([1, null, $owner_node_id, "owner_{$i}", 1, 'strong']);
            $edge_stmt->execute([
                1, $owner_node_id, $properties_node_id,
                'object_properties', 1, 'strong',
            ]);
            $edge_stmt->execute([
                1, $properties_node_id, $prop_value_node_id,
                'edges', 1, 'strong',
            ]);
        }
    }

    private function createDirectDb(): \PDO
    {
        $db = new \PDO('sqlite:' . $this->db_path);
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $db->exec('CREATE TABLE IF NOT EXISTS runs (run_id INTEGER PRIMARY KEY, created_at TEXT NOT NULL)');
        $db->exec("INSERT INTO runs (run_id, created_at) VALUES (1, '2024-01-01T00:00:00Z')");

        $db->exec('
            CREATE TABLE IF NOT EXISTS context_nodes (
                run_id INTEGER NOT NULL,
                node_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                canonical_node_id INTEGER,
                PRIMARY KEY (run_id, node_id)
            )
        ');
        $db->exec("
            CREATE TABLE IF NOT EXISTS context_edges (
                id INTEGER PRIMARY KEY,
                run_id INTEGER NOT NULL,
                parent_node_id INTEGER,
                child_node_id INTEGER NOT NULL,
                link_name TEXT NOT NULL,
                is_tree INTEGER NOT NULL,
                strength TEXT NOT NULL DEFAULT 'strong'
            )
        ");
        $db->exec('
            CREATE TABLE IF NOT EXISTS context_node_locations (
                id INTEGER PRIMARY KEY,
                run_id INTEGER NOT NULL,
                node_id INTEGER NOT NULL,
                address BIGINT,
                size BIGINT,
                location_type TEXT NOT NULL,
                class_name TEXT,
                string_value TEXT,
                refcount BIGINT,
                type_info BIGINT,
                region TEXT
            )
        ');

        return $db;
    }
}
