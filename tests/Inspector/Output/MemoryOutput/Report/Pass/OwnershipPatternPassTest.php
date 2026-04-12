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

class OwnershipPatternPassTest extends BaseTestCase
{
    private string $db_path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db_path = tempnam(sys_get_temp_dir(), 'reli_ownership_test_') . '.db';
    }

    protected function tearDown(): void
    {
        @unlink($this->db_path);
        parent::tearDown();
    }

    /**
     * Canonical 1:1 ownership: 120 App\Owner objects each hold a fresh
     * App\Owned via the $payload property. Coverage should be 100%, the
     * resolved property name should be `payload`, and the finding kind
     * should be `ownership_pattern`.
     */
    public function testReportsOneToOneOwnershipForOver100Instances(): void
    {
        $db = $this->createDirectDb();
        $this->seedOwnership($db, owner_count: 120, owned_count: 120);

        $substrate = GraphSubstrate::loadFromDb($db, 1);
        $pass = new OwnershipPatternPass($substrate, $db, 1);
        $findings = $pass->analyze();

        $ownership = array_values(array_filter(
            $findings,
            fn (Finding $f) => $f->kind === 'ownership_pattern',
        ));
        $this->assertCount(1, $ownership, 'Single 1:1 ownership pattern expected');

        $facts = $ownership[0]->facts;
        $this->assertSame('App\\Owned', $facts['owned_class']);
        $this->assertSame(120, $facts['owned_count']);
        $this->assertSame(100.0, $facts['coverage_pct']);

        // The owners list should record exactly one entry, naming the
        // owner class, instance count, and the linking property.
        $this->assertIsArray($facts['owners']);
        $this->assertCount(1, $facts['owners']);
        $owner = $facts['owners'][0];
        $this->assertSame('App\\Owner', $owner['owner']);
        $this->assertSame('App\\Owned', $owner['owned']);
        $this->assertSame(120, $owner['owner_count']);
        $this->assertSame(120, $owner['owned_count']);
        $this->assertSame('payload', $owner['prop']);
    }

    /**
     * Below the 100-instance owner threshold the pass should stay silent
     * even when every single owner technically holds an owned instance.
     */
    public function testIgnoresOwnersBelow100InstanceThreshold(): void
    {
        $db = $this->createDirectDb();
        $this->seedOwnership($db, owner_count: 50, owned_count: 50);

        $substrate = GraphSubstrate::loadFromDb($db, 1);
        $pass = new OwnershipPatternPass($substrate, $db, 1);
        $findings = $pass->analyze();

        $this->assertSame([], array_values(array_filter(
            $findings,
            fn (Finding $f) => $f->kind === 'ownership_pattern',
        )));
    }

    /**
     * 120 owners but only 80 carry an owned instance: rate is 0.667,
     * below the 0.9 threshold, so the pass should suppress the finding
     * (the relationship is too noisy to call "1:1 ownership").
     */
    public function testIgnoresWhenOwnershipRateIsBelow90Percent(): void
    {
        $db = $this->createDirectDb();
        $this->seedOwnership($db, owner_count: 120, owned_count: 80);

        $substrate = GraphSubstrate::loadFromDb($db, 1);
        $pass = new OwnershipPatternPass($substrate, $db, 1);
        $findings = $pass->analyze();

        $this->assertSame([], array_values(array_filter(
            $findings,
            fn (Finding $f) => $f->kind === 'ownership_pattern',
        )));
    }

    /**
     * Insert a synthetic root and `$owner_count` instances of App\Owner.
     * Each owner gets an `object_properties` child; the first
     * `$owned_count` of those property contexts hold a `payload`
     * pointing to a fresh App\Owned instance, the rest are empty.
     *
     * Inlining everything as exec strings keeps the fixture obvious at
     * the cost of being a little chatty — fine for ~120 owners.
     */
    private function seedOwnership(\PDO $db, int $owner_count, int $owned_count): void
    {
        // Synthetic root so the owner objects have a tree parent. The
        // pass doesn't care about the root link_name, but the substrate
        // loader needs every node to be reachable for sane subtree
        // accounting.
        $db->exec("INSERT INTO context_nodes (run_id, node_id, type) VALUES
            (1, 1, 'RootContext')
        ");
        $db->exec("INSERT INTO context_edges
            (run_id, parent_node_id, child_node_id, link_name, is_tree, strength) VALUES
            (1, NULL, 1, 'call_frames', 1, 'strong')
        ");

        $next_id = 2;
        $next_addr = 0x1000;
        for ($i = 0; $i < $owner_count; $i++) {
            $owner_id = $next_id++;
            $props_id = $next_id++;
            $owner_addr = $next_addr++;

            $db->exec("INSERT INTO context_nodes (run_id, node_id, type) VALUES
                (1, {$owner_id}, 'ObjectContext'),
                (1, {$props_id}, 'ObjectPropertiesContext')
            ");
            $db->exec("INSERT INTO context_node_locations
                (run_id, node_id, address, size, location_type, class_name) VALUES
                (1, {$owner_id}, {$owner_addr}, 64, 'ZendObjectMemoryLocation', 'App\\Owner')
            ");
            $db->exec("INSERT INTO context_edges
                (run_id, parent_node_id, child_node_id, link_name, is_tree, strength) VALUES
                (1, 1, {$owner_id}, 'obj', 1, 'strong'),
                (1, {$owner_id}, {$props_id}, 'object_properties', 1, 'strong')
            ");

            if ($i < $owned_count) {
                $owned_id = $next_id++;
                $owned_addr = $next_addr++;
                $db->exec("INSERT INTO context_nodes (run_id, node_id, type) VALUES
                    (1, {$owned_id}, 'ObjectContext')
                ");
                $db->exec("INSERT INTO context_node_locations
                    (run_id, node_id, address, size, location_type, class_name) VALUES
                    (1, {$owned_id}, {$owned_addr}, 32, 'ZendObjectMemoryLocation', 'App\\Owned')
                ");
                $db->exec("INSERT INTO context_edges
                    (run_id, parent_node_id, child_node_id, link_name, is_tree, strength) VALUES
                    (1, {$props_id}, {$owned_id}, 'payload', 1, 'strong')
                ");
            }
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
