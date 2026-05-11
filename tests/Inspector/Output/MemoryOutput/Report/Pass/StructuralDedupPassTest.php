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

use PHPUnit\Framework\Attributes\DataProvider;
use Reli\BaseTestCase;
use Reli\Inspector\Output\MemoryOutput\Report\Finding;

class StructuralDedupPassTest extends BaseTestCase
{
    private string $db_path = '';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $path = tempnam(sys_get_temp_dir(), 'reli_structural_dedup_test_');
        self::assertNotFalse($path);
        $this->db_path = $path . '.db';
    }

    #[\Override]
    protected function tearDown(): void
    {
        @unlink($this->db_path);
        parent::tearDown();
    }

    public function testEmptyObjectFindingFiresForUserDefinedClass(): void
    {
        $db = $this->createDirectDb();
        $this->seedEmptyClassScenario($db, 'App\\Marker', 60);

        $substrate = \Reli\Inspector\Output\MemoryOutput\Report\Substrate\GraphSubstrate::loadFromDb($db, 1);
        $findings = (new StructuralDedupPass($db, 1, $substrate))->analyze();

        $this->assertNotNull($this->findFinding(
            $findings,
            'empty_object',
            'App\\Marker',
        ));
    }

    #[DataProvider('internalClassNamesProvider')]
    public function testEmptyObjectFindingSkipsInternalClasses(string $class_name): void
    {
        // N1 fix: internal PHP classes (Closure, Generator, Reflection*,
        // PDO, ...) report zero user-declared properties because their
        // state is C-side. The "may be replaceable" advice doesn't apply,
        // and prior reports flooded with empty_object: Closure noise.
        $db = $this->createDirectDb();
        $this->seedEmptyClassScenario($db, $class_name, 60);

        $substrate = \Reli\Inspector\Output\MemoryOutput\Report\Substrate\GraphSubstrate::loadFromDb($db, 1);
        $findings = (new StructuralDedupPass($db, 1, $substrate))->analyze();

        $this->assertNull(
            $this->findFinding($findings, 'empty_object', $class_name),
            "empty_object should not fire for internal class {$class_name}",
        );
    }

    /** @return array<string, array{0: string}> */
    public static function internalClassNamesProvider(): array
    {
        return [
            'Closure' => ['Closure'],
            'Generator' => ['Generator'],
            'WeakMap' => ['WeakMap'],
            'ReflectionClass' => ['ReflectionClass'],
            'ReflectionMethod' => ['ReflectionMethod'],
            'DateTimeImmutable' => ['DateTimeImmutable'],
            'DOMDocument' => ['DOMDocument'],
            'PDOStatement' => ['PDOStatement'],
            'SplObjectStorage' => ['SplObjectStorage'],
        ];
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

    private function seedEmptyClassScenario(\PDO $db, string $class_name, int $count): void
    {
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

        // N instances of $class_name with no object_properties — i.e. zero
        // user-declared properties. Each takes 64 bytes shallow.
        for ($i = 0; $i < $count; $i++) {
            $node_id = 1000 + $i;
            $node_stmt->execute([
                1,
                $node_id,
                1_000_000 + $i,
                64,
                'ZendObjectMemoryLocation',
                $class_name,
                null,
            ]);
            // Stable container for the SQL fallback path's
            // object_properties JOIN — required for the SQL row to be
            // returned, but we also exercise the substrate path which
            // uses iterateNodeClasses().
            $props_node_id = 5000 + $i;
            $edge_stmt->execute([1, null, $node_id, "owner_{$i}", 1, 'strong']);
            $edge_stmt->execute([
                1, $node_id, $props_node_id,
                'object_properties', 1, 'strong',
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
