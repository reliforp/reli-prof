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

class NonTreeEdgePassTest extends BaseTestCase
{
    private string $db_path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db_path = tempnam(sys_get_temp_dir(), 'reli_non_tree_edge_test_') . '.db';
    }

    protected function tearDown(): void
    {
        @unlink($this->db_path);
        parent::tearDown();
    }

    public function testAnalyzeWithSubstrateMatchesSqlPathForRepresentativeFindings(): void
    {
        $db = $this->createDirectDb();
        $this->seedRepresentativeScenario($db);

        $sql_findings = (new NonTreeEdgePass($db, 1))->analyze();

        $substrate = GraphSubstrate::loadFromDb($db, 1);
        $graph_findings = (new NonTreeEdgePass($db, 1, $substrate))->analyze();

        $shared_sql = $this->findFinding(
            $sql_findings,
            'shared_singleton',
            'App\\Owner::$service',
        );
        $shared_graph = $this->findFinding(
            $graph_findings,
            'shared_singleton',
            'App\\Owner::$service',
        );
        $this->assertNotNull($shared_sql);
        $this->assertNotNull($shared_graph);
        $this->assertSame($shared_sql->summary, $shared_graph->summary);
        $this->assertSame($shared_sql->facts, $shared_graph->facts);

        $dedup_sql = $this->findFinding(
            $sql_findings,
            'dedup_candidate',
            'App\\Owner::$names[value]',
        );
        $dedup_graph = $this->findFinding(
            $graph_findings,
            'dedup_candidate',
            'App\\Owner::$names[value]',
        );
        $this->assertNotNull($dedup_sql);
        $this->assertNotNull($dedup_graph);
        $this->assertSame($dedup_sql->summary, $dedup_graph->summary);
        $this->assertSame(
            $dedup_sql->facts['count'],
            $dedup_graph->facts['count'],
        );
        $this->assertSame(
            $dedup_sql->facts['examples']['sample_value'],
            $dedup_graph->facts['examples']['sample_value'],
        );
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

        $edge_stmt->execute([1, null, 1, 'call_frames', 1, 'strong']);
        $node_stmt->execute([
            1,
            900,
            9000,
            64,
            'ZendObjectMemoryLocation',
            'App\\Service',
            null,
        ]);

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

            $edge_stmt->execute([1, 1, $owner_node_id, "owner_{$i}", 1, 'strong']);
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
                900,
                'service',
                0,
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
