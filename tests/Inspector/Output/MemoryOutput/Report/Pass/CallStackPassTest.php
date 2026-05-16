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
     * Three call frames under a `call_frames` root: the substrate
     * path should resolve each "frame_no" link to its function/lineno
     * label via NodeLabeler and emit one call_stack finding with all
     * three frames in order, carrying the default "Call Stack at
     * capture:" header for the formatter.
     */
    public function testReportsCallStackFromSubstrate(): void
    {
        $db = $this->createDirectDb();
        $this->seedCallFramesRoot(
            $db,
            root_node_id: 1,
            link_name: 'call_frames',
            frames: [
                ['fn' => 'main', 'line' => '1'],
                ['fn' => 'App\\Foo::bar', 'line' => '42'],
                ['fn' => 'App\\Foo::baz', 'line' => '99'],
            ],
            first_frame_id: 100,
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
        $this->assertSame('Call Stack at capture:', $finding->facts['header']);
        $this->assertStringContainsString('main:1', $finding->summary);
    }

    /**
     * When the memory_limit traceback succeeded, the graph has both
     * a `memory_limit_call_frames` root (the recovered pre-fatal
     * stack) and a `call_frames` root (the shutdown handler that
     * woke the sidecar). The pass should emit both findings — OOM
     * first as the primary "Call Stack at memory_limit:" block,
     * then the at-capture one labelled as "Captured inside shutdown
     * handler:" so the formatter renders it as a sub-block.
     */
    public function testSurfacesMemoryLimitStackAheadOfShutdownHandlerStack(): void
    {
        $db = $this->createDirectDb();
        $this->seedCallFramesRoot(
            $db,
            root_node_id: 1,
            link_name: 'memory_limit_call_frames',
            frames: [
                ['fn' => 'App\\Bottom::oom', 'line' => '99'],
                ['fn' => 'App\\Middle::call', 'line' => '17'],
                ['fn' => '<main>', 'line' => '3'],
            ],
            first_frame_id: 100,
        );
        $this->seedCallFramesRoot(
            $db,
            root_node_id: 2,
            link_name: 'call_frames',
            frames: [
                ['fn' => 'Reli\\Sidecar\\Client\\MemoryLimitHandler::{closure}', 'line' => '114'],
            ],
            first_frame_id: 200,
        );

        $substrate = GraphSubstrate::loadFromDb($db, 1);
        $pass = new CallStackPass($db, 1, $substrate);
        $findings = $pass->analyze();

        $this->assertCount(2, $findings, 'both call-stack findings should be emitted');
        $this->assertSame('Call Stack at memory_limit:', $findings[0]->facts['header']);
        $this->assertSame(
            ['App\\Bottom::oom:99', 'App\\Middle::call:17', '<main>:3'],
            $findings[0]->facts['frames'],
        );
        $this->assertSame('Captured inside shutdown handler:', $findings[1]->facts['header']);
        $this->assertSame(
            ['Reli\\Sidecar\\Client\\MemoryLimitHandler::{closure}:114'],
            $findings[1]->facts['frames'],
        );
    }

    /**
     * When only `memory_limit_call_frames` is present (unusual but
     * possible if the at-capture stack was never emitted), the pass
     * should still surface it as the primary "Call Stack at
     * memory_limit:" finding rather than falling back to nothing.
     */
    public function testSurfacesMemoryLimitStackWhenNoCaptureStack(): void
    {
        $db = $this->createDirectDb();
        $this->seedCallFramesRoot(
            $db,
            root_node_id: 1,
            link_name: 'memory_limit_call_frames',
            frames: [['fn' => '<main>', 'line' => '1']],
            first_frame_id: 100,
        );

        $substrate = GraphSubstrate::loadFromDb($db, 1);
        $pass = new CallStackPass($db, 1, $substrate);
        $findings = $pass->analyze();

        $this->assertCount(1, $findings);
        $this->assertSame('Call Stack at memory_limit:', $findings[0]->facts['header']);
    }

    /**
     * No CallFramesContext at all → empty result.
     */
    public function testReturnsEmptyWhenNoCallFramesContext(): void
    {
        $db = $this->createDirectDb();
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
     * Insert a CallFramesContext root with one CallFrameContext
     * child per frame. Each child carries `function_name` (and
     * optionally `lineno`) attributes — the same shape NodeLabeler
     * expects.
     *
     * @param list<array{fn:string, line?:string}> $frames
     */
    private function seedCallFramesRoot(
        \PDO $db,
        int $root_node_id,
        string $link_name,
        array $frames,
        int $first_frame_id,
    ): void {
        $db->exec("INSERT INTO context_nodes (run_id, node_id, type) VALUES
            (1, {$root_node_id}, 'CallFramesContext')
        ");
        // CallFramesContext needs a location row so the substrate's
        // node_sizes index includes it.
        $db->exec("INSERT INTO context_node_locations
            (run_id, node_id, address, size, location_type, class_name) VALUES
            (1, {$root_node_id}, {$root_node_id}000, 0, 'ZendVmStackMemoryLocation', NULL)
        ");
        $db->exec("INSERT INTO context_edges
            (run_id, parent_node_id, child_node_id, link_name, is_tree, strength) VALUES
            (1, NULL, {$root_node_id}, '{$link_name}', 1, 'strong')
        ");

        $next_id = $first_frame_id;
        $next_addr = $first_frame_id * 0x100;
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
                (1, {$root_node_id}, {$node_id}, '{$i}', 1, 'strong')
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
