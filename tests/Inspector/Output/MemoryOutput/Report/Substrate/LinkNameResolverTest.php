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

class LinkNameResolverTest extends BaseTestCase
{
    private \PDO $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->exec(<<<'SQL'
            CREATE TABLE context_edges (
                id INTEGER PRIMARY KEY,
                run_id INTEGER NOT NULL,
                parent_node_id INTEGER,
                child_node_id INTEGER NOT NULL,
                link_name TEXT NOT NULL,
                is_tree INTEGER NOT NULL,
                strength TEXT
            );
        SQL);

        // Run 1 (target).
        $this->insertEdge(1, 1, 10, 'foo', is_tree: 1);
        $this->insertEdge(1, 1, 11, 'bar', is_tree: 1);
        $this->insertEdge(1, 2, 12, 'object_properties', is_tree: 1);
        // Non-tree edge for the same child should be ignored.
        $this->insertEdge(1, 9, 11, 'should_be_ignored', is_tree: 0);
        // Root edge — link_name is known, but parent_node_id is NULL.
        $this->insertEdgeWithNullParent(1, 13, 'root_link', is_tree: 1);
        // Different run — must not leak across runs.
        $this->insertEdge(2, 1, 10, 'wrong_run', is_tree: 1);
    }

    public function testLazyLookupReturnsTreeEdgeName(): void
    {
        $resolver = new LinkNameResolver($this->db, 1);

        $this->assertSame('foo', $resolver->lookup(10));
        $this->assertSame('bar', $resolver->lookup(11));
        $this->assertSame('object_properties', $resolver->lookup(12));
    }

    public function testLazyLookupReturnsNullForUnknownChild(): void
    {
        $resolver = new LinkNameResolver($this->db, 1);

        $this->assertNull($resolver->lookup(999));
    }

    public function testLookupCachesResultsAndAvoidsRequery(): void
    {
        $resolver = new LinkNameResolver($this->db, 1);
        $this->assertSame('foo', $resolver->lookup(10));

        // Drop the underlying row; a cached lookup must still return 'foo'.
        $this->db->exec('DELETE FROM context_edges WHERE child_node_id = 10');
        $this->assertSame('foo', $resolver->lookup(10));
    }

    public function testLoadAllPopulatesCacheInOneQueryAndScopesByRun(): void
    {
        $resolver = new LinkNameResolver($this->db, 1);
        $resolver->loadAll();

        // Subsequent lookups must not need the database any more.
        $this->db->exec('DELETE FROM context_edges');

        $this->assertSame('foo', $resolver->lookup(10));
        $this->assertSame('bar', $resolver->lookup(11));
        $this->assertSame('object_properties', $resolver->lookup(12));
        // Unknown ids must be cached as null after a full load (no re-query).
        $this->assertNull($resolver->lookup(999));
    }

    public function testLoadAllIsScopedByRunId(): void
    {
        $resolver = new LinkNameResolver($this->db, 2);
        $resolver->loadAll();

        $this->assertSame('wrong_run', $resolver->lookup(10));
        // run_id=2 has no edge for child 11.
        $this->assertNull($resolver->lookup(11));
    }

    public function testLoadAllIsIdempotent(): void
    {
        $resolver = new LinkNameResolver($this->db, 1);
        $resolver->loadAll();
        $resolver->loadAll();

        $this->assertSame('foo', $resolver->lookup(10));
    }

    public function testNonTreeEdgesAreIgnored(): void
    {
        $resolver = new LinkNameResolver($this->db, 1);
        // child 11 has BOTH a tree edge ('bar') and a non-tree edge —
        // only the tree edge label must be returned.
        $this->assertSame('bar', $resolver->lookup(11));

        $resolver2 = new LinkNameResolver($this->db, 1);
        $resolver2->loadAll();
        $this->assertSame('bar', $resolver2->lookup(11));
    }

    public function testGetTreeParentLazy(): void
    {
        $resolver = new LinkNameResolver($this->db, 1);
        $this->assertSame(1, $resolver->getTreeParent(10));
        $this->assertSame(2, $resolver->getTreeParent(12));
        // Root edge: link_name exists, parent does not.
        $this->assertNull($resolver->getTreeParent(13));
        $this->assertSame('root_link', $resolver->lookup(13));
        // Unknown child.
        $this->assertNull($resolver->getTreeParent(999));
    }

    public function testTreeParentMapAndLinkMapAfterLoadAll(): void
    {
        $resolver = new LinkNameResolver($this->db, 1);
        $resolver->loadAll();

        $parents = $resolver->getTreeParentMap();
        $links = $resolver->getTreeLinkMap();

        // Parent map: only edges with non-NULL parent.
        $this->assertSame(
            [10 => 1, 11 => 1, 12 => 2],
            $parents,
        );
        // Link map: every tree edge, including the root edge.
        $this->assertSame(
            [10 => 'foo', 11 => 'bar', 12 => 'object_properties', 13 => 'root_link'],
            $links,
        );
    }

    public function testTreeMapAccessorsRequireLoadAll(): void
    {
        $resolver = new LinkNameResolver($this->db, 1);

        $this->expectException(\LogicException::class);
        $resolver->getTreeParentMap();
    }

    public function testGetTreeLinkMapAlsoRequiresLoadAll(): void
    {
        $resolver = new LinkNameResolver($this->db, 1);

        $this->expectException(\LogicException::class);
        $resolver->getTreeLinkMap();
    }

    public function testIsFullyLoadedReflectsLoadAllState(): void
    {
        $resolver = new LinkNameResolver($this->db, 1);
        $this->assertFalse($resolver->isFullyLoaded());

        $resolver->loadAll();
        $this->assertTrue($resolver->isFullyLoaded());
    }

    public function testLookupManyReturnsKnownEntriesAndCachesMisses(): void
    {
        $resolver = new LinkNameResolver($this->db, 1);

        $result = $resolver->lookupMany([10, 11, 13, 999]);

        $this->assertSame(
            [
                10 => 'foo',
                11 => 'bar',
                13 => 'root_link',
            ],
            $result,
        );

        // After the bulk fetch, the unknown id must be cached as a miss
        // so a follow-up lookup() does not re-query the database.
        $this->db->exec('DELETE FROM context_edges');
        $this->assertNull($resolver->lookup(999));
        // And resolved entries are still served from the cache.
        $this->assertSame('foo', $resolver->lookup(10));
        $this->assertSame(1, $resolver->getTreeParent(10));
    }

    public function testLookupManyServesCachedAndDbBackedEntriesTogether(): void
    {
        $resolver = new LinkNameResolver($this->db, 1);
        // Pre-warm one entry via lookup().
        $this->assertSame('foo', $resolver->lookup(10));

        $result = $resolver->lookupMany([10, 11, 12]);
        $this->assertSame(
            [
                10 => 'foo',                  // from cache
                11 => 'bar',                  // from db
                12 => 'object_properties',    // from db
            ],
            $result,
        );
    }

    public function testLookupManyAfterLoadAllUsesCacheOnly(): void
    {
        $resolver = new LinkNameResolver($this->db, 1);
        $resolver->loadAll();

        // Drop everything; lookupMany must still answer from the cache.
        $this->db->exec('DELETE FROM context_edges');

        $result = $resolver->lookupMany([10, 11, 12, 13, 999]);
        $this->assertSame(
            [
                10 => 'foo',
                11 => 'bar',
                12 => 'object_properties',
                13 => 'root_link',
            ],
            $result,
        );
    }

    public function testLookupManyOnEmptyInputReturnsEmpty(): void
    {
        $resolver = new LinkNameResolver($this->db, 1);
        $this->assertSame([], $resolver->lookupMany([]));
    }

    public function testCacheCapStopsGrowingButStillResolves(): void
    {
        // Cap the cache at 1 entry. The first lookup populates it; the second
        // executes SQL but does not store, leaving the cache size at 1.
        $resolver = new LinkNameResolver($this->db, 1, max_cache_entries: 1);

        $this->assertSame('foo', $resolver->lookup(10));
        $this->assertSame('bar', $resolver->lookup(11));

        // Drop the underlying row for the cached id; cached lookup still wins.
        $this->db->exec('DELETE FROM context_edges WHERE child_node_id = 10');
        $this->assertSame('foo', $resolver->lookup(10));

        // Drop the underlying row for the *uncached* id; lookup must now miss
        // because nothing was ever stored for it.
        $this->db->exec('DELETE FROM context_edges WHERE child_node_id = 11');
        $this->assertNull($resolver->lookup(11));
    }

    private function insertEdge(
        int $run_id,
        int $parent_id,
        int $child_id,
        string $link_name,
        int $is_tree,
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO context_edges (run_id, parent_node_id, child_node_id, link_name, is_tree, strength)'
            . " VALUES (?, ?, ?, ?, ?, 'strong')"
        );
        $stmt->execute([$run_id, $parent_id, $child_id, $link_name, $is_tree]);
    }

    private function insertEdgeWithNullParent(
        int $run_id,
        int $child_id,
        string $link_name,
        int $is_tree,
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO context_edges (run_id, parent_node_id, child_node_id, link_name, is_tree, strength)'
            . " VALUES (?, NULL, ?, ?, ?, 'strong')"
        );
        $stmt->execute([$run_id, $child_id, $link_name, $is_tree]);
    }
}
