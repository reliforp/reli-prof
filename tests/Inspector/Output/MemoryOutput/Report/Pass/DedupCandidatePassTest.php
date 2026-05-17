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

class DedupCandidatePassTest extends BaseTestCase
{
    private string $db_path = '';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $path = tempnam(sys_get_temp_dir(), 'reli_dedup_candidate_test_');
        self::assertNotFalse($path);
        $this->db_path = $path . '.db';
    }

    #[\Override]
    protected function tearDown(): void
    {
        @unlink($this->db_path);
        parent::tearDown();
    }

    public function testAnalyzeWithSubstrateProducesDedupFinding(): void
    {
        $db = $this->createDirectDb();
        $this->seedRepresentativeScenario($db);

        $substrate = GraphSubstrate::loadFromDb($db, 1);
        $graph_findings = (new DedupCandidatePass(
            $substrate,
            $substrate->getDedupCandidateStats(10),
        ))->analyze();

        $dedup_graph = $this->findFinding(
            $graph_findings,
            'dedup_candidate',
            'App\\Owner::$names[value]',
        );

        $this->assertNotNull($dedup_graph);
        $this->assertSame(60, $dedup_graph->facts['count']);
        $graph_examples = $dedup_graph->facts['examples'] ?? null;
        self::assertIsArray($graph_examples);
        $this->assertSame(
            'same-shared-string',
            $graph_examples['sample_value'] ?? null,
        );
    }

    public function testAnalyzeDoesNotTreatSharedSingletonAsDedupCandidate(): void
    {
        $db = $this->createDirectDb();
        $this->seedSharedSingletonScenario($db);

        $substrate = GraphSubstrate::loadFromDb($db, 1);
        $findings = (new DedupCandidatePass(
            $substrate,
            $substrate->getDedupCandidateStats(10),
        ))->analyze();

        $this->assertNull($this->findFinding(
            $findings,
            'dedup_candidate',
            'App\\Owner::$names[value]',
        ));
    }

    public function testAnalyzeKeepsHeterogeneousClassesInSeparateBuckets(): void
    {
        // Regression for B6 (memory-report-ux-improvements.md): the SQL
        // GROUP BY used to key on (link_name, node_size) only, so two
        // different classes that happened to share a slot name and shallow
        // size would land in the same bucket. The bigger of the two would
        // dominate the sample-mean retained size and inflate impact_bytes.
        // After the fix, target_class is part of the group key, so a
        // mixed bucket cannot form.
        $db = $this->createDirectDb();
        $this->seedHeterogeneousClassScenario($db);

        $substrate = GraphSubstrate::loadFromDb($db, 1);
        $findings = (new DedupCandidatePass(
            $substrate,
            $substrate->getDedupCandidateStats(10),
        ))->analyze();

        // Without the per-class group key the SQL would have produced a
        // single bucket of count=120 mixing both classes. With the fix
        // each class produces its own bucket of count=60.
        $alpha = $this->findFinding(
            $findings,
            'dedup_candidate',
            'App\\Alpha',
        );
        $beta = $this->findFinding(
            $findings,
            'dedup_candidate',
            'App\\Beta',
        );

        $this->assertNotNull($alpha, 'Alpha bucket should be present');
        $this->assertNotNull($beta, 'Beta bucket should be present');
        $this->assertSame(60, $alpha->facts['count']);
        $this->assertSame(60, $beta->facts['count']);
    }

    public function testAnalyzeUsesUnionSumForSharedSubtree(): void
    {
        // T2.1: when N dedup-bucket members all live inside an SCC's
        // spanning tree, `total_waste` should reflect the **union** of
        // their reachable tree-edge subtrees — each node counted once —
        // not `cnt × sample_mean(retained)`, which over-counts the
        // spanning-tree ancestors O(N²) and previously required a
        // heap-total clamp.
        //
        // Scenario: 60 distinct dedup targets connected in a tree
        // chain (target1 → target2 → ... → target60). Each target is
        // 256 B. retained(target_i) = 256 × (60 - i + 1) — spans
        // [256, 256×60]. Pre-T2.1 mean ≈ 256 × 30.5 = 7808; cnt × mean
        // ≈ 60 × 7808 = 468,480 — would have been clamped previously.
        // With union: 60 × 256 = 15,360.
        $db = $this->createDirectDb();
        $this->seedTreeChainSharedScenario($db);

        $substrate = GraphSubstrate::loadFromDb($db, 1);
        $findings = (new DedupCandidatePass(
            $substrate,
            $substrate->getDedupCandidateStats(10),
        ))->analyze();

        $finding = $this->findFinding(
            $findings,
            'dedup_candidate',
            'App\\Owner::$names[value]',
        );
        $this->assertNotNull($finding);
        $this->assertSame(60, $finding->facts['count']);
        $this->assertSame(15360, $finding->facts['total_waste']);
        $this->assertArrayNotHasKey(
            'total_waste_unclamped',
            $finding->facts,
            'union sum is intrinsically bounded; no clamp metadata',
        );
        $this->assertStringNotContainsString('clamped', $finding->hypothesis);
    }

    private function seedTreeChainSharedScenario(\PDO $db): void
    {
        $edge_stmt = $db->prepare(
            'INSERT INTO context_edges'
            . ' (run_id, parent_node_id, child_node_id, link_name, is_tree, strength)'
            . ' VALUES (?, ?, ?, ?, ?, ?)'
        );
        $node_stmt = $db->prepare(
            'INSERT INTO context_node_locations'
            . ' (run_id, node_id, address, size, location_type, class_name, string_value)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        // 60 target nodes connected as a tree chain. Each owner's
        // dedup edge points at a distinct target — bucket has 60
        // members. The chain makes each target's retained_size span
        // 1..60 nodes, so cnt × sample_mean over-counts heavily; the
        // union from any subset of members visits the same 60-node
        // chain once.
        for ($i = 0; $i < 60; $i++) {
            $target_id = 800000 + $i;
            $node_stmt->execute([
                1, $target_id, 8_000_000 + $i, 256,
                'ZendObjectMemoryLocation', 'App\\SharedTarget', null,
            ]);
        }
        for ($i = 0; $i < 59; $i++) {
            // tree edge target_i → target_{i+1}
            $edge_stmt->execute([
                1, 800000 + $i, 800001 + $i, "next_{$i}", 1, 'strong',
            ]);
        }

        // 60 owners, each linking (non-tree) to one of the 60 distinct
        // chain targets via the same bucket key (link='value', size=256,
        // class='App\\SharedTarget').
        for ($i = 0; $i < 60; $i++) {
            $owner_id = 1000 + $i * 10;
            $props_id = $owner_id + 1;
            $arr_header_id = $owner_id + 2;
            $arr_elems_id = $owner_id + 3;
            $arr_elem_id = $owner_id + 4;
            $target_id = 800000 + $i;

            $node_stmt->execute([
                1, $owner_id, 1_000_000 + $i, 64,
                'ZendObjectMemoryLocation', 'App\\Owner', null,
            ]);
            $node_stmt->execute([
                1, $arr_header_id, 2_000_000 + $i, 56,
                'ZendArrayMemoryLocation', null, null,
            ]);

            $edge_stmt->execute([1, null, $owner_id, "owner_{$i}", 1, 'strong']);
            $edge_stmt->execute([
                1, $owner_id, $props_id, 'object_properties', 1, 'strong',
            ]);
            $edge_stmt->execute([
                1, $props_id, $arr_header_id, 'names', 1, 'strong',
            ]);
            $edge_stmt->execute([
                1, $arr_header_id, $arr_elems_id, 'array_elements', 1, 'strong',
            ]);
            $edge_stmt->execute([
                1, $arr_elems_id, $arr_elem_id, '0', 1, 'strong',
            ]);
            // Non-tree dedup edge to one of the 60 distinct targets.
            $edge_stmt->execute([
                1, $arr_elem_id, $target_id, 'value', 0, 'strong',
            ]);
        }
    }

    private function seedHeterogeneousClassScenario(\PDO $db): void
    {
        $edge_stmt = $db->prepare(
            'INSERT INTO context_edges'
            . ' (run_id, parent_node_id, child_node_id, link_name, is_tree, strength)'
            . ' VALUES (?, ?, ?, ?, ?, ?)'
        );
        $node_stmt = $db->prepare(
            'INSERT INTO context_node_locations'
            . ' (run_id, node_id, address, size, location_type, class_name, string_value)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        // Two classes (Alpha + Beta), both 64 B shallow, both linked via
        // the same slot name `value`. Without per-class bucketing, they'd
        // collapse into a single (link='value', size=64) bucket of size
        // 120; with it they should stay separate.
        $classes = [
            ['App\\Alpha', 0],
            ['App\\Beta', 5000],
        ];

        foreach ($classes as [$class_name, $base]) {
            for ($i = 0; $i < 60; $i++) {
                $owner_node_id = 10000 + $base + $i * 5;
                $properties_node_id = $owner_node_id + 1;
                $array_header_node_id = $owner_node_id + 2;
                $array_elements_node_id = $owner_node_id + 3;
                $target_node_id = 200000 + $base + $i;

                $node_stmt->execute([
                    1,
                    $owner_node_id,
                    1000000 + $base + $i,
                    72,
                    'ZendObjectMemoryLocation',
                    $class_name,
                    null,
                ]);
                $node_stmt->execute([
                    1,
                    $array_header_node_id,
                    2000000 + $base + $i,
                    56,
                    'ZendArrayMemoryLocation',
                    null,
                    null,
                ]);
                // Target is an instance of the same class — 256 B shallow
                // (shallow * count must exceed the 10240 HAVING threshold).
                $node_stmt->execute([
                    1,
                    $target_node_id,
                    3000000 + $base + $i,
                    256,
                    'ZendObjectMemoryLocation',
                    $class_name,
                    null,
                ]);

                $edge_stmt->execute([1, null, $owner_node_id, "owner_{$base}_{$i}", 1, 'strong']);
                $edge_stmt->execute([
                    1, $owner_node_id, $properties_node_id,
                    'object_properties', 1, 'strong',
                ]);
                $edge_stmt->execute([
                    1, $properties_node_id, $array_header_node_id,
                    'children', 1, 'strong',
                ]);
                $edge_stmt->execute([
                    1, $array_header_node_id, $array_elements_node_id,
                    'array_elements', 1, 'strong',
                ]);
                // Non-tree strong edge to the dedup target — same slot
                // name and size across both classes.
                $edge_stmt->execute([
                    1, $array_elements_node_id, $target_node_id,
                    'value', 0, 'strong',
                ]);
            }
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

    private function seedRepresentativeScenario(\PDO $db): void
    {
        $edge_stmt = $db->prepare(
            'INSERT INTO context_edges'
            . ' (run_id, parent_node_id, child_node_id, link_name, is_tree, strength)'
            . ' VALUES (?, ?, ?, ?, ?, ?)'
        );
        $node_stmt = $db->prepare(
            'INSERT INTO context_node_locations'
            . ' (run_id, node_id, address, size, location_type, class_name, string_value)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        for ($i = 0; $i < 60; $i++) {
            $owner_node_id = 1000 + $i * 10;
            $properties_node_id = $owner_node_id + 1;
            $array_header_node_id = $owner_node_id + 2;
            $array_elements_node_id = $owner_node_id + 3;
            $array_element_node_id = $owner_node_id + 4;
            $string_node_id = 100000 + $i;

            $node_stmt->execute([
                1,
                $owner_node_id,
                1000000 + $i,
                64,
                'ZendObjectMemoryLocation',
                'App\\Owner',
                null,
            ]);
            $node_stmt->execute([
                1,
                $array_header_node_id,
                2000000 + $i,
                56,
                'ZendArrayMemoryLocation',
                null,
                null,
            ]);
            $node_stmt->execute([
                1,
                $string_node_id,
                3000000 + $i,
                256,
                'ZendStringMemoryLocation',
                null,
                'same-shared-string',
            ]);

            $edge_stmt->execute([1, null, $owner_node_id, "owner_{$i}", 1, 'strong']);
            $edge_stmt->execute([
                1,
                $owner_node_id,
                $properties_node_id,
                'object_properties',
                1,
                'strong',
            ]);
            $edge_stmt->execute([
                1,
                $properties_node_id,
                $array_header_node_id,
                'names',
                1,
                'strong',
            ]);
            $edge_stmt->execute([
                1,
                $array_header_node_id,
                $array_elements_node_id,
                'array_elements',
                1,
                'strong',
            ]);
            $edge_stmt->execute([
                1,
                $array_elements_node_id,
                $array_element_node_id,
                '0',
                1,
                'strong',
            ]);
            $edge_stmt->execute([
                1,
                $array_element_node_id,
                $string_node_id,
                'value',
                0,
                'strong',
            ]);
        }
    }

    private function seedSharedSingletonScenario(\PDO $db): void
    {
        $edge_stmt = $db->prepare(
            'INSERT INTO context_edges'
            . ' (run_id, parent_node_id, child_node_id, link_name, is_tree, strength)'
            . ' VALUES (?, ?, ?, ?, ?, ?)'
        );
        $node_stmt = $db->prepare(
            'INSERT INTO context_node_locations'
            . ' (run_id, node_id, address, size, location_type, class_name, string_value)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        $shared_string_node_id = 200000;
        $node_stmt->execute([
            1,
            $shared_string_node_id,
            5000000,
            256,
            'ZendStringMemoryLocation',
            null,
            'single-shared-string',
        ]);

        for ($i = 0; $i < 120; $i++) {
            $owner_node_id = 3000 + $i * 10;
            $properties_node_id = $owner_node_id + 1;
            $array_header_node_id = $owner_node_id + 2;
            $array_elements_node_id = $owner_node_id + 3;
            $array_element_node_id = $owner_node_id + 4;

            $node_stmt->execute([
                1,
                $owner_node_id,
                6000000 + $i,
                64,
                'ZendObjectMemoryLocation',
                'App\\Owner',
                null,
            ]);
            $node_stmt->execute([
                1,
                $array_header_node_id,
                7000000 + $i,
                56,
                'ZendArrayMemoryLocation',
                null,
                null,
            ]);

            $edge_stmt->execute([1, null, $owner_node_id, "owner_{$i}", 1, 'strong']);
            $edge_stmt->execute([
                1,
                $owner_node_id,
                $properties_node_id,
                'object_properties',
                1,
                'strong',
            ]);
            $edge_stmt->execute([
                1,
                $properties_node_id,
                $array_header_node_id,
                'names',
                1,
                'strong',
            ]);
            $edge_stmt->execute([
                1,
                $array_header_node_id,
                $array_elements_node_id,
                'array_elements',
                1,
                'strong',
            ]);
            $edge_stmt->execute([
                1,
                $array_elements_node_id,
                $array_element_node_id,
                '0',
                1,
                'strong',
            ]);
            $edge_stmt->execute([
                1,
                $array_element_node_id,
                $shared_string_node_id,
                'value',
                0,
                'strong',
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
        $db->exec('
            CREATE TABLE IF NOT EXISTS context_node_attributes (
                id INTEGER PRIMARY KEY,
                run_id INTEGER NOT NULL,
                node_id INTEGER NOT NULL,
                key TEXT NOT NULL,
                value TEXT
            )
        ');

        return $db;
    }
}
