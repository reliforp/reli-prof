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

class NodeLabelerTest extends BaseTestCase
{
    private string $db_path = '';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $path = tempnam(sys_get_temp_dir(), 'reli_node_labeler_test_');
        self::assertNotFalse($path);
        $this->db_path = $path . '.db';
    }

    #[\Override]
    protected function tearDown(): void
    {
        @unlink($this->db_path);
        parent::tearDown();
    }

    public function testResolvesCanonicalNameRegardlessOfNameEdgeTreeFlag(): void
    {
        // Regression for G4: when a class is registered after its name
        // string has already been collected by another path (autoload,
        // opcache-loaded interned strings), the resulting `name` edge
        // is recorded as `is_tree = 0` (back-reference to an existing
        // node). The canonical-name lookup must accept those too — in
        // real captures that's roughly half of all user-defined
        // ClassDefinitionContexts, including the ones a reader cares
        // about (Composer\Autoload\..., framework classes, the test's
        // own classes, etc.).
        $db = $this->createDirectDb();

        $tree_class_node_id = 100;
        $tree_name_node_id = 101;
        $back_ref_class_node_id = 200;
        $back_ref_name_node_id = 201;

        // Two ClassDefinitionContext nodes: one whose `name` edge
        // happens to be `is_tree = 1` (the internal class case), and
        // one whose `name` edge is `is_tree = 0` (the back-reference
        // case G4 used to drop). Same `link_name = 'name'`, same
        // string-location target shape — only the flag differs.
        $this->insertContextNode($db, $tree_class_node_id, 'ClassDefinitionContext');
        $this->insertContextNode($db, $back_ref_class_node_id, 'ClassDefinitionContext');

        $this->insertStringLocation($db, $tree_name_node_id, 'Internal\\Class');
        $this->insertStringLocation($db, $back_ref_name_node_id, 'App\\UserClass');

        // is_tree = 1
        $this->insertEdge(
            $db,
            $tree_class_node_id,
            $tree_name_node_id,
            'name',
            is_tree: 1,
        );
        // is_tree = 0 — back-reference to an already-collected string
        $this->insertEdge(
            $db,
            $back_ref_class_node_id,
            $back_ref_name_node_id,
            'name',
            is_tree: 0,
        );

        $labeler = new NodeLabeler($db, 1);

        $this->assertSame(
            'Internal\\Class',
            $labeler->resolvePathLabel('original_label', $tree_class_node_id),
            'is_tree = 1 (internal-first) case still resolved',
        );
        $this->assertSame(
            'App\\UserClass',
            $labeler->resolvePathLabel('original_label', $back_ref_class_node_id),
            'is_tree = 0 (back-reference) case is now resolved (G4 fix)',
        );
    }

    public function testResolvesCanonicalNameForFunctionDefinitions(): void
    {
        $db = $this->createDirectDb();

        $func_node_id = 300;
        $name_node_id = 301;
        $internal_func_node_id = 400;
        $internal_name_node_id = 401;

        $this->insertContextNode($db, $func_node_id, 'UserFunctionDefinitionContext');
        $this->insertContextNode($db, $internal_func_node_id, 'InternalFunctionDefinitionContext');

        $this->insertStringLocation($db, $name_node_id, 'doSomething');
        $this->insertStringLocation($db, $internal_name_node_id, 'strlen');

        $this->insertEdge(
            $db,
            $func_node_id,
            $name_node_id,
            'name',
            is_tree: 0,
        );
        $this->insertEdge(
            $db,
            $internal_func_node_id,
            $internal_name_node_id,
            'name',
            is_tree: 1,
        );

        $labeler = new NodeLabeler($db, 1);

        $this->assertSame(
            'doSomething',
            $labeler->resolvePathLabel('?', $func_node_id),
        );
        $this->assertSame(
            'strlen',
            $labeler->resolvePathLabel('?', $internal_func_node_id),
        );
    }

    public function testFallsBackToRawLinkNameWhenNoCanonicalName(): void
    {
        $db = $this->createDirectDb();

        $labeler = new NodeLabeler($db, 1);
        $this->assertSame(
            'unmapped_link',
            $labeler->resolvePathLabel('unmapped_link', 9999),
        );
    }

    private function insertContextNode(\PDO $db, int $node_id, string $type): void
    {
        $stmt = $db->prepare(
            'INSERT INTO context_nodes (run_id, node_id, type) VALUES (?, ?, ?)'
        );
        $stmt->execute([1, $node_id, $type]);
    }

    private function insertStringLocation(\PDO $db, int $node_id, string $value): void
    {
        $stmt = $db->prepare(
            'INSERT INTO context_node_locations'
            . ' (run_id, node_id, address, size, location_type, class_name, string_value)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            1,
            $node_id,
            0x1000 + $node_id,
            strlen($value),
            'ZendStringMemoryLocation',
            null,
            $value,
        ]);
    }

    private function insertEdge(
        \PDO $db,
        int $parent_node_id,
        int $child_node_id,
        string $link_name,
        int $is_tree,
    ): void {
        $stmt = $db->prepare(
            'INSERT INTO context_edges'
            . ' (run_id, parent_node_id, child_node_id, link_name, is_tree, strength)'
            . ' VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([1, $parent_node_id, $child_node_id, $link_name, $is_tree, 'strong']);
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
