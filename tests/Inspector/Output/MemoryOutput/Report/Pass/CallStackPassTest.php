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
use Reli\Inspector\Output\MemoryOutput\Report\FindingSeverity;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\GraphSubstrate;

class CallStackPassTest extends BaseTestCase
{
    private string $db_path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db_path = tempnam(sys_get_temp_dir(), 'reli_call_stack_test_') . '.db';
    }

    protected function tearDown(): void
    {
        @unlink($this->db_path);
        parent::tearDown();
    }

    /**
     * Three call frames under a CallFramesContext: the substrate path
     * should resolve each "frame_no" link to its function/lineno label
     * via NodeLabeler and emit one call_stack finding with all three
     * frames in order.
     */
    public function testReportsCallStackFromSubstrate(): void
    {
        $db = $this->createDirectDb();
        $this->seedCallStack(
            $db,
            [
                ['fn' => 'main', 'line' => '1'],
                ['fn' => 'App\\Foo::bar', 'line' => '42'],
                ['fn' => 'App\\Foo::baz', 'line' => '99'],
            ],
        );

        $substrate = GraphSubstrate::loadFromDb($db, 1);
        $pass = new CallStackPass($db, 1, $substrate);
        $findings = $pass->analyze();

        $this->assertCount(1, $findings);
        $finding = $findings[0];
        $this->assertSame('call_stack', $finding->kind);
        $this->assertSame(FindingSeverity::Info, $finding->severity);
        $this->assertSame(3, $finding->facts['depth']);
        $this->assertSame(
            ['main:1', 'App\\Foo::bar:42', 'App\\Foo::baz:99'],
            $finding->facts['frames'],
        );
        $this->assertStringContainsString('main:1', $finding->summary);
    }

    /**
     * No CallFramesContext at all → empty result. Mirrors the early
     * exit in analyzeWithSubstrate.
     */
    public function testReturnsEmptyWhenNoCallFramesContext(): void
    {
        $db = $this->createDirectDb();
        // Single object node, no call frames anywhere.
        $db->exec("INSERT INTO context_nodes (run_id, node_id, type) VALUES
            (1, 1, 'RootContext'),
            (1, 2, 'ObjectContext')
        ");
        $db->exec("INSERT INTO context_node_locations
            (run_id, node_id, address, size, location_type, class_name) VALUES
            (1, 2, 1000, 64, 'ZendObjectMemoryLocation', 'App\\Lone')
        ");
        $db->exec("INSERT INTO context_edges
            (run_id, parent_node_id, child_node_id, link_name, is_tree, strength) VALUES
            (1, NULL, 1, 'objects_store', 1, 'strong'),
            (1, 1, 2, 'obj', 1, 'strong')
        ");

        $substrate = GraphSubstrate::loadFromDb($db, 1);
        $pass = new CallStackPass($db, 1, $substrate);

        $this->assertSame([], $pass->analyze());
    }

    /**
     * Insert a CallFramesContext with one CallFrameContext child per
     * frame. Each child carries `function_name` (and optionally
     * `lineno`) attributes — the same shape NodeLabeler / the SQL
     * pass query expect.
     *
     * @param list<array{fn:string, line?:string}> $frames
     */
    private function seedCallStack(\PDO $db, array $frames): void
    {
        $db->exec("INSERT INTO context_nodes (run_id, node_id, type) VALUES
            (1, 1, 'RootContext'),
            (1, 2, 'CallFramesContext')
        ");
        // CallFramesContext needs a location row so the substrate's
        // node_sizes index includes it (the substrate path iterates
        // node_sizes when scanning for the CallFramesContext node).
        $db->exec("INSERT INTO context_node_locations
            (run_id, node_id, address, size, location_type, class_name) VALUES
            (1, 2, 1000, 0, 'ZendVmStackMemoryLocation', NULL)
        ");
        $db->exec("INSERT INTO context_edges
            (run_id, parent_node_id, child_node_id, link_name, is_tree, strength) VALUES
            (1, NULL, 1, 'call_frames_root', 1, 'strong'),
            (1, 1, 2, 'call_frames', 1, 'strong')
        ");

        $next_id = 100;
        $next_addr = 0x10000;
        foreach ($frames as $i => $frame) {
            $node_id = $next_id++;
            $addr = $next_addr++;
            $db->exec("INSERT INTO context_nodes (run_id, node_id, type) VALUES
                (1, {$node_id}, 'CallFrameContext')
            ");
            $db->exec("INSERT INTO context_node_locations
                (run_id, node_id, address, size, location_type, class_name) VALUES
                (1, {$node_id}, {$addr}, 0, 'ZendExecuteDataMemoryLocation', NULL)
            ");
            $db->exec("INSERT INTO context_edges
                (run_id, parent_node_id, child_node_id, link_name, is_tree, strength) VALUES
                (1, 2, {$node_id}, '{$i}', 1, 'strong')
            ");

            $fn = $frame['fn'];
            $stmt = $db->prepare(
                'INSERT INTO context_node_attributes (run_id, node_id, key, value)'
                . ' VALUES (1, ?, ?, ?)'
            );
            $stmt->execute([$node_id, 'function_name', $fn]);
            if (isset($frame['line'])) {
                $stmt->execute([$node_id, 'lineno', $frame['line']]);
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
