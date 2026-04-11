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

use PhpCast\Cast;

/**
 * Shared in-memory index of the tree edges of a memory snapshot.
 *
 * Multiple report passes (PerPropertyMemoryPass, OwnershipPatternPass,
 * StructuralDedupPass, PropertyScalingPass, NonTreeEdgePass) need to
 * translate a child node id into the parent edge's `link_name` and/or
 * `parent_node_id`. Without sharing, every pass issued one prepared
 * `LIMIT 1` query per edge (or its own full sweep of the tree edges) —
 * an N+1 / duplicated-scan pattern that dominated the report runtime.
 *
 * The resolver has two modes:
 *
 *   1. Eager mode (`loadAll()`). One sequential scan populates both
 *      `child → link_name` and `child → parent_node_id` maps. Subsequent
 *      lookups are pure memory hits. This is the fast path, used when
 *      the graph is small enough that the resulting maps fit in the
 *      memory budget.
 *
 *   2. Lazy mode. A single shared prepared statement serves on-demand
 *      lookups, with results cached up to `$max_cache_entries`. Once
 *      the cache is full, additional lookups still execute SQL but
 *      stop adding to the cache so memory stays bounded. This is the
 *      fallback for graphs too large to materialise (e.g. multi-GB
 *      memory dumps producing 10M+ edges).
 *
 * `getTreeParentMap()` and `getTreeLinkMap()` are only meaningful in
 * eager mode and will refuse to return data otherwise — callers that
 * need them on huge graphs must keep their own per-pass code path.
 */
final class LinkNameResolver
{
    /** @var array<int, string|null> child_node_id => link_name|null */
    private array $link_cache = [];

    /** @var array<int, int> child_node_id => parent_node_id (only set for non-null parents) */
    private array $tree_parents = [];

    private bool $fully_loaded = false;

    private ?\PDOStatement $stmt = null;

    /**
     * @param int $max_cache_entries soft cap on entries the lazy cache will
     *                               retain. Defaults to 1M, ~100 MB worst-case.
     * @param ?GraphSubstrate $substrate when supplied AND its tree-edge link
     *                                   index has been built, lookups skip
     *                                   SQL entirely and answer in O(1) from
     *                                   the substrate. The 4 N+1 passes get
     *                                   this for free without code changes.
     */
    public function __construct(
        private \PDO $db,
        private int $run_id,
        private int $max_cache_entries = 1_000_000,
        private ?GraphSubstrate $substrate = null,
    ) {
    }

    /**
     * Eagerly load every tree edge into memory in a single query.
     *
     * Cheap when the graph fits in memory. Idempotent. Callers must
     * have decided that materialising the entire tree is acceptable
     * for the current memory budget — see `ReportGenerator` for the
     * threshold logic.
     *
     * @psalm-suppress MixedAssignment
     */
    public function loadAll(): void
    {
        if ($this->fully_loaded) {
            return;
        }
        $stmt = $this->db->query(
            "SELECT parent_node_id, child_node_id, link_name"
            . " FROM context_edges"
            . " WHERE is_tree = 1 AND run_id = {$this->run_id}"
        );
        while (($row = $stmt->fetch(\PDO::FETCH_NUM)) !== false) {
            assert(is_array($row));
            $child_id = Cast::toInt($row[1]);
            $this->link_cache[$child_id] = Cast::toString($row[2]);
            if ($row[0] !== null) {
                $this->tree_parents[$child_id] = Cast::toInt($row[0]);
            }
        }
        $this->fully_loaded = true;
    }

    public function isFullyLoaded(): bool
    {
        return $this->fully_loaded;
    }

    /**
     * Resolve a batch of tree-edge link names in one shot.
     *
     * Cached entries are returned immediately. The remaining ids are
     * fetched in a single chunked `IN (...)` query (chunk size 900,
     * comfortably under SQLite's default 1000-arg expression limit) and
     * recorded in the cache up to its soft cap. Cache-bypass behaviour
     * once the cap is reached matches {@see self::lookup()}.
     *
     * @param  iterable<int>     $child_node_ids
     * @return array<int, string> child_node_id => link_name (only resolved entries)
     * @psalm-suppress MixedAssignment
     */
    public function lookupMany(iterable $child_node_ids): array
    {
        // Substrate fast path: walk the supplied ids and answer each in
        // O(1) from the in-memory index — no chunked IN, no SQL at all.
        if ($this->substrate !== null && $this->substrate->hasTreeLinkIndex()) {
            /** @var array<int, string> $result */
            $result = [];
            foreach ($child_node_ids as $id) {
                $name = $this->substrate->getTreeLinkName($id);
                if ($name !== null) {
                    $result[$id] = $name;
                }
            }
            return $result;
        }

        /** @var array<int, true> dedupe + missing-id collection */
        $missing = [];
        /** @var array<int, string> $result */
        $result = [];
        foreach ($child_node_ids as $id) {
            if (array_key_exists($id, $this->link_cache)) {
                $cached = $this->link_cache[$id];
                if ($cached !== null) {
                    $result[$id] = $cached;
                }
                continue;
            }
            if ($this->fully_loaded) {
                // Authoritative miss: record null so we never re-query.
                $this->link_cache[$id] = null;
                continue;
            }
            $missing[$id] = true;
        }

        if ($missing === []) {
            return $result;
        }

        // Chunked IN — keeps the SQL size bounded and lets SQLite use
        // the (run_id, child_node_id) index for each batch.
        $missing_ids = array_keys($missing);
        foreach (array_chunk($missing_ids, 900) as $chunk) {
            $placeholders = implode(',', $chunk);
            $stmt = $this->db->query(
                "SELECT parent_node_id, child_node_id, link_name"
                . " FROM context_edges"
                . " WHERE is_tree = 1 AND run_id = {$this->run_id}"
                . " AND child_node_id IN ({$placeholders})"
            );
            while (($row = $stmt->fetch(\PDO::FETCH_NUM)) !== false) {
                assert(is_array($row));
                $child_id = Cast::toInt($row[1]);
                $link_name = Cast::toString($row[2]);
                $result[$child_id] = $link_name;
                if (count($this->link_cache) < $this->max_cache_entries) {
                    $this->link_cache[$child_id] = $link_name;
                    if ($row[0] !== null) {
                        $this->tree_parents[$child_id] = Cast::toInt($row[0]);
                    }
                }
                unset($missing[$child_id]);
            }
        }
        // Any ids still in $missing have no tree edge — record nulls so
        // future lookups are O(1).
        if ($missing !== [] && count($this->link_cache) < $this->max_cache_entries) {
            foreach (array_keys($missing) as $id) {
                if (count($this->link_cache) >= $this->max_cache_entries) {
                    break;
                }
                $this->link_cache[$id] = null;
            }
        }

        return $result;
    }

    /**
     * Resolve the tree-edge `link_name` for a child node.
     *
     * Returns `null` if no tree edge points to this child.
     */
    public function lookup(int $child_node_id): ?string
    {
        // Substrate fast path: O(1) in-memory hit, no SQL.
        if ($this->substrate !== null && $this->substrate->hasTreeLinkIndex()) {
            return $this->substrate->getTreeLinkName($child_node_id);
        }

        if (array_key_exists($child_node_id, $this->link_cache)) {
            return $this->link_cache[$child_node_id];
        }

        // After loadAll() any miss is authoritative — record and return null
        // so we never re-query.
        if ($this->fully_loaded) {
            return $this->link_cache[$child_node_id] = null;
        }

        return $this->fillFromDb($child_node_id)['link_name'];
    }

    /**
     * Resolve the tree-edge parent for a child node, or `null` if there
     * is no parent (root) or no tree edge for this child.
     */
    public function getTreeParent(int $child_node_id): ?int
    {
        if ($this->substrate !== null && $this->substrate->hasTreeLinkIndex()) {
            return $this->substrate->getTreeParentNodeId($child_node_id);
        }

        if (array_key_exists($child_node_id, $this->link_cache)) {
            return $this->tree_parents[$child_node_id] ?? null;
        }
        if ($this->fully_loaded) {
            $this->link_cache[$child_node_id] = null;
            return null;
        }
        return $this->fillFromDb($child_node_id)['parent_id'];
    }

    /**
     * @return array<int, int> child_node_id => parent_node_id (root edges omitted)
     * @throws \LogicException if called before `loadAll()` — partial maps would
     *                         silently mislead callers, so the resolver refuses.
     */
    public function getTreeParentMap(): array
    {
        if (!$this->fully_loaded) {
            throw new \LogicException(
                'getTreeParentMap() requires loadAll() to have been called first.'
            );
        }
        return $this->tree_parents;
    }

    /**
     * @return array<int, string> child_node_id => link_name (only known children)
     * @throws \LogicException if called before `loadAll()`.
     */
    public function getTreeLinkMap(): array
    {
        if (!$this->fully_loaded) {
            throw new \LogicException(
                'getTreeLinkMap() requires loadAll() to have been called first.'
            );
        }
        /** @var array<int, string> $links */
        $links = [];
        foreach ($this->link_cache as $child_id => $link_name) {
            if ($link_name !== null) {
                $links[$child_id] = $link_name;
            }
        }
        return $links;
    }

    /**
     * @return array{link_name: ?string, parent_id: ?int}
     * @psalm-suppress MixedAssignment
     */
    private function fillFromDb(int $child_node_id): array
    {
        if ($this->stmt === null) {
            $this->stmt = $this->db->prepare(
                "SELECT parent_node_id, link_name FROM context_edges"
                . " WHERE child_node_id = ? AND is_tree = 1"
                . " AND run_id = {$this->run_id} LIMIT 1"
            );
        }
        $this->stmt->execute([$child_node_id]);
        /** @var array{0: int|string|null, 1: string}|false $row */
        $row = $this->stmt->fetch(\PDO::FETCH_NUM);

        $link_name = $row === false ? null : $row[1];
        $parent_id = $row === false || $row[0] === null ? null : Cast::toInt($row[0]);

        // Bounded cache: stop storing once the soft cap is reached so the
        // resolver still helps the first N unique ids without growing
        // unboundedly on huge graphs.
        if (count($this->link_cache) < $this->max_cache_entries) {
            $this->link_cache[$child_node_id] = $link_name;
            if ($parent_id !== null) {
                $this->tree_parents[$child_node_id] = $parent_id;
            }
        }

        return ['link_name' => $link_name, 'parent_id' => $parent_id];
    }
}
