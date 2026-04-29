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

use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Format;
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Reader as BinaryReader;

class GraphSubstrate
{
    /** @var array<int, int> node_id => shallow size */
    public array $node_sizes = [];

    /** @var array<int, list<int>> parent_id => [child_id, ...] (tree edges only) */
    public array $children = [];

    /** @var array<int, list<int>> parent_id => [child_id, ...] (all edges including non-tree) */
    public array $all_children = [];

    /** @var array<int, list<int>> child_id => [parent_id, ...] (all edges) */
    public array $all_parents = [];

    /**
     * Tree-edge link_name index — child_node_id → link_name. Populated
     * during {@see loadEdges()} so report passes can answer
     * "what's the link_name of this child?" without per-row SQL.
     *
     * @var array<int, string>
     */
    public array $tree_link_names = [];

    /**
     * Tree-edge parent index — child_node_id → parent_node_id (or -1
     * for the synthetic root). Populated during {@see loadEdges()}.
     *
     * @var array<int, int>
     */
    public array $tree_parents = [];

    /** @var array<int, list<int>> parent_id => [child_id, ...] (strong tree edges only) */
    public array $strong_children = [];

    /** @var array<int, list<int>> parent_id => [child_id, ...] (strong edges including non-tree) */
    public array $strong_all_children = [];

    /** @var list<int> root node IDs (parent_node_id IS NULL) */
    public array $roots = [];

    /** @var array<int, int> node_id => subtree size */
    public array $subtree_sizes = [];

    /** @var array<int, string> node_id => class name */
    public array $node_classes = [];

    /**
     * Per-node context type ("PhpReferenceContext", "ObjectPropertiesContext",
     * etc.). Populated alongside loadNodeSizes so the path-walking passes
     * can answer "what is this node?" without per-row prepared statements.
     *
     * @var array<int, string>
     */
    public array $node_types = [];

    /** @var array<int, int> node_id => scc_id */
    public array $node_to_scc = [];

    /**
     * @var list<array{
     *     id: int,
     *     nodes: list<int>,
     *     node_count: int,
     *     total_size: int,
     *     ext_in: int,
     *     ext_out: int,
     *     class_counts: array<string, int>,
     *     signature: string,
     *     single_owner_likelihood: string,
     * }>
     */
    public array $scc_profiles = [];

    public int $edge_count = 0;

    /** @var array<int, int> node_id => canonical node_id (Union-Find parent) */
    protected array $canonical = [];

    /** @var array<int, list<int>> canonical_node_id => [original_node_id, ...] */
    protected array $canonicalToOriginals = [];

    /** @var array<int, list<int>> canonical_parent => [canonical_child, ...] for SCC */
    protected array $scc_adjacency = [];

    private const int FFI_CSR_THRESHOLD = 2_000_000;

    /** @psalm-suppress UnsafeInstantiation */
    public static function loadFromDb(\PDO $db, int $run_id): static
    {
        $substrate = new static();
        $substrate->loadNodeSizes($db, $run_id);
        $substrate->loadNodeTypes($db, $run_id);
        $substrate->loadEdges($db, $run_id);
        $substrate->loadAddressMapping($db, $run_id);
        $substrate->buildSccAdjacency();
        $substrate->computeSubtreeSizes();
        $substrate->computeScc();
        return $substrate;
    }

    /**
     * Factory method that automatically selects the best implementation.
     *
     * For graphs with > 2M edges, uses FFI CSR to reduce memory by ~50-100x.
     * For smaller graphs, uses PHP arrays (faster for small datasets).
     *
     * @param bool|null $forceFfiCsr true=force on, false=force off, null=auto
     * @param int $bulk_fetch_chunk Rows per chunked fetchAll inside
     *   the FFI substrate loaders. 0 disables chunking entirely
     *   (legacy per-row cursor scan, lowest peak memory). The
     *   chunked path uses index-friendly pagination keys
     *   (`(run_id, node_id)` covering index on context_nodes,
     *   `(run_id, id)` index on context_edges) — both are installed
     *   lazily by ReportGenerator::ensureReportIndexes. The PHP-array
     *   fallback substrate ignores the parameter.
     */
    public static function createFromDb(
        \PDO $db,
        int $run_id,
        ?bool $forceFfiCsr = null,
        int $bulk_fetch_chunk = 0,
    ): self {
        if ($forceFfiCsr === false) {
            return self::loadFromDb($db, $run_id);
        }

        $useFfi = $forceFfiCsr === true;
        if (!$useFfi) {
            $edge_count = (int)$db->query(
                "SELECT count(*) FROM context_edges WHERE run_id = {$run_id}"
            )->fetchColumn();
            $useFfi = $edge_count > self::FFI_CSR_THRESHOLD;
        }

        if ($useFfi && extension_loaded('ffi')) {
            return FfiCsrGraphSubstrate::loadFromDb($db, $run_id, $bulk_fetch_chunk);
        }
        return self::loadFromDb($db, $run_id);
    }

    /**
     * Load the substrate from a .rmem binary file (PHP-array variant).
     *
     * @psalm-suppress UnsafeInstantiation
     * @psalm-suppress MixedAssignment
     */
    public static function loadFromBinary(BinaryReader $reader, bool $useCache = true, bool $rebuildCache = false, bool $skipScc = false): static
    {
        $substrate = new static();
        $dict = $reader->getStringDict();

        // Load nodes (node sizes come from locations, but we need types from nodes)
        if ($reader->hasSection(Format::SECTION_NODES)) {
            $data = $reader->getSectionData(Format::SECTION_NODES);
            $count = $reader->getSectionElementCount(Format::SECTION_NODES);
            $offset = 0;
            for ($i = 0; $i < $count; $i++) {
                $row = unpack('Vnode_id/Vcanonical_id/Vtype_id/Vclass_id', $data, $offset);
                assert(is_array($row));
                /** @var array{node_id: int, canonical_id: int, type_id: int, class_id: int} $row */
                $offset += Format::NODE_ROW_SIZE;
                $node_id = $row['node_id'];
                $type = $dict->lookup($row['type_id']);
                if ($type !== null) {
                    $substrate->node_types[$node_id] = $type;
                }
                $canonical_id = $row['canonical_id'];
                if ($canonical_id !== 0 && $canonical_id !== $node_id) {
                    $substrate->canonical[$node_id] = $canonical_id;
                    $substrate->canonical[$canonical_id] = $canonical_id;
                }
            }
        }

        // Load locations → node_sizes + node_classes
        if ($reader->hasSection(Format::SECTION_LOCATIONS)) {
            $data = $reader->getSectionData(Format::SECTION_LOCATIONS);
            $count = $reader->getSectionElementCount(Format::SECTION_LOCATIONS);
            $offset = 0;
            for ($i = 0; $i < $count; $i++) {
                $row = unpack(
                    'Vnode_id/Vlocation_type_id/Vclass_id/Paddress/Psize/'
                    . 'Vstring_value_id/Vrefcount/Vtype_info/Vregion_id/Vbin_overhead',
                    $data,
                    $offset,
                );
                assert(is_array($row));
                /** @var array{node_id: int, location_type_id: int, class_id: int, address: int, size: int, string_value_id: int, refcount: int, type_info: int, region_id: int, bin_overhead: int} $row */
                $offset += Format::LOCATION_ROW_SIZE;
                $node_id = $row['node_id'];
                $size = $row['size'];
                $substrate->node_sizes[$node_id] = ($substrate->node_sizes[$node_id] ?? 0) + $size;

                $class_id = $row['class_id'];
                if ($class_id !== Format::NULL_STRING_ID && !isset($substrate->node_classes[$node_id])) {
                    $cls = $dict->lookup($class_id);
                    if ($cls !== null) {
                        $substrate->node_classes[$node_id] = $cls;
                    }
                }
            }
        }

        // Load edges
        if ($reader->hasSection(Format::SECTION_EDGES)) {
            $data = $reader->getSectionData(Format::SECTION_EDGES);
            $count = $reader->getSectionElementCount(Format::SECTION_EDGES);
            $offset = 0;
            $edge_count = 0;
            for ($i = 0; $i < $count; $i++) {
                $row = unpack('Vparent_node_id/Vchild_node_id/Vlink_name_id/Cis_tree/Cstrength/v_pad', $data, $offset);
                assert(is_array($row));
                /** @var array{parent_node_id: int, child_node_id: int, link_name_id: int, is_tree: int, strength: int, _pad: int} $row */
                $offset += Format::EDGE_ROW_SIZE;
                $edge_count++;

                $raw_parent = $row['parent_node_id'];
                $parent = $raw_parent === 0xFFFFFFFF ? -1 : $raw_parent;
                $child = $row['child_node_id'];
                $is_tree = $row['is_tree'];
                $is_strong = $row['strength'] === 0; // 0 = strong

                if ($is_tree) {
                    $substrate->children[$parent][] = $child;
                    if ($is_strong) {
                        $substrate->strong_children[$parent][] = $child;
                    }
                    if ($parent === -1) {
                        $substrate->roots[] = $child;
                    }
                    $link_name = $dict->lookup($row['link_name_id']);
                    if ($link_name !== null) {
                        $substrate->tree_link_names[$child] = $link_name;
                    }
                    $substrate->tree_parents[$child] = $parent;
                }
                if ($parent !== -1) {
                    $substrate->all_children[$parent][] = $child;
                    if ($is_strong) {
                        $substrate->strong_all_children[$parent][] = $child;
                    }
                }
                $substrate->all_parents[$child][] = $parent;
            }
            $substrate->edge_count = $edge_count;
        }

        // Compute canonical node IDs from address grouping (same logic as
        // PdoMemoryOutput::computeCanonicalNodeIds but from binary locations).
        if ($reader->hasSection(Format::SECTION_LOCATIONS)) {
            $locData = $reader->getSectionData(Format::SECTION_LOCATIONS);
            $locCount = $reader->getSectionElementCount(Format::SECTION_LOCATIONS);
            /** @var array<int, list<int>> $addr_to_nodes */
            $addr_to_nodes = [];
            $offset = 0;
            for ($i = 0; $i < $locCount; $i++) {
                $node_id_row = unpack('V', $locData, $offset);
                assert(is_array($node_id_row));
                $address_row = unpack('P', $locData, $offset + 12);
                assert(is_array($address_row));
                $node_id = $node_id_row[1];
                $address = $address_row[1];
                $offset += Format::LOCATION_ROW_SIZE;
                if ($address !== 0) {
                    $addr_to_nodes[$address][] = $node_id;
                }
            }
            unset($locData);

            foreach ($addr_to_nodes as $nodes) {
                $unique = array_values(array_unique($nodes));
                if (count($unique) <= 1) {
                    continue;
                }
                $canon = min($unique);
                foreach ($unique as $nid) {
                    $substrate->canonical[$nid] = $canon;
                    $substrate->canonical[$canon] = $canon;
                }
            }
            unset($addr_to_nodes);
        }

        // Build canonical reverse mapping
        if ($substrate->canonical !== []) {
            foreach ($substrate->canonical as $node => $parent) {
                $canon = $substrate->findCanonical($node);
                $substrate->canonicalToOriginals[$canon][] = $node;
            }
            foreach ($substrate->canonicalToOriginals as $canon => $nodes) {
                $substrate->canonicalToOriginals[$canon] = array_values(array_unique($nodes));
            }
        }

        $substrate->buildSccAdjacency();
        $substrate->computeSubtreeSizes();
        $substrate->computeScc();
        return $substrate;
    }

    /**
     * Factory that auto-selects PHP array or FFI CSR from a binary file.
     */
    public static function createFromBinary(
        BinaryReader $reader,
        ?bool $forceFfiCsr = null,
        bool $useCache = true,
        bool $rebuildCache = false,
        bool $skipScc = false,
    ): self {
        if ($forceFfiCsr === false) {
            return self::loadFromBinary($reader, $useCache, $rebuildCache, $skipScc);
        }

        if (extension_loaded('ffi')) {
            // Always prefer FFI CSR — it supports sidecar cache,
            // on-disk CSR, and is needed by explore/serve.
            return FfiCsrGraphSubstrate::loadFromBinary($reader, $useCache, $rebuildCache, $skipScc);
        }
        return self::loadFromBinary($reader, $useCache, $rebuildCache, $skipScc);
    }

    // ---- Accessor methods ----
    // These can be overridden by subclasses (e.g. FfiCsrGraphSubstrate)
    // to provide alternative storage backends.

    /** @return list<int> */
    public function getChildren(int $nodeId): array
    {
        return $this->children[$nodeId] ?? [];
    }

    /** @return list<int> */
    public function getStrongChildren(int $nodeId): array
    {
        return $this->strong_children[$nodeId] ?? [];
    }

    /** @return list<int> */
    public function getAllChildren(int $nodeId): array
    {
        return $this->all_children[$nodeId] ?? [];
    }

    /** @return list<int> */
    public function getAllParents(int $nodeId): array
    {
        return $this->all_parents[$nodeId] ?? [];
    }

    public function getIncomingCount(int $nodeId): int
    {
        return count($this->all_parents[$nodeId] ?? []);
    }

    public function getNodeSize(int $nodeId): int
    {
        return $this->node_sizes[$nodeId] ?? 0;
    }

    public function getSubtreeSize(int $nodeId): int
    {
        return $this->subtree_sizes[$nodeId] ?? 0;
    }

    /**
     * Sum of `node_size` over the union of strong tree-edge subtrees
     * rooted at the given seeds. Each reachable node is counted once
     * even if multiple seeds reach it — i.e., this is "memory currently
     * sitting under any of these N seeds", not "sum of per-seed retained
     * sizes".
     *
     * Used by `DedupCandidatePass` and `PropertyScalingPass` to compute
     * `impact_bytes` honestly under DAG / SCC sharing. The previous
     * `cnt × sample_mean(retained)` aggregation over-counted spanning-
     * tree ancestors when N seeds share an SCC and was capped with a
     * heap-total clamp; the union value is intrinsically bounded by
     * the heap and needs no clamp.
     *
     * BFS over `getStrongChildren()` (tree-edge strong children, the
     * same edge set `computeSubtreeSizes()` walks). Per-call cost is
     * O(|union|) regardless of seed count, because the visited set
     * skips re-traversal.
     *
     * @param list<int> $seeds
     */
    public function unionReachableTreeSize(array $seeds): int
    {
        if ($seeds === []) {
            return 0;
        }
        $visited = [];
        $stack = [];
        foreach ($seeds as $seed) {
            if (isset($visited[$seed])) {
                continue;
            }
            $stack[] = $seed;
            while ($stack !== []) {
                $node = array_pop($stack);
                if (isset($visited[$node])) {
                    continue;
                }
                $visited[$node] = true;
                foreach ($this->getStrongChildren($node) as $child) {
                    if (!isset($visited[$child])) {
                        $stack[] = $child;
                    }
                }
            }
        }
        $sum = 0;
        foreach ($visited as $node_id => $_) {
            $sum += $this->getNodeSize($node_id);
        }
        return $sum;
    }

    public function getNodeClass(int $nodeId): ?string
    {
        return $this->node_classes[$nodeId] ?? null;
    }

    /**
     * Context node type for the given node id, e.g. "PhpReferenceContext"
     * or "ObjectPropertiesContext". Returns null when the node has no
     * recorded type or isn't in the loaded run.
     */
    public function getNodeType(int $nodeId): ?string
    {
        return $this->node_types[$nodeId] ?? null;
    }

    /** @return list<int> */
    public function getRoots(): array
    {
        return $this->roots;
    }

    /**
     * @return list<array{
     *     id: int,
     *     nodes: list<int>,
     *     node_count: int,
     *     total_size: int,
     *     ext_in: int,
     *     ext_out: int,
     *     class_counts: array<string, int>,
     *     signature: string,
     *     single_owner_likelihood: string,
     * }>
     */
    public function getSccProfiles(): array
    {
        return $this->scc_profiles;
    }

    public function getEdgeCount(): int
    {
        return $this->edge_count;
    }

    public function getNodeCount(): int
    {
        return count($this->node_sizes);
    }

    public function hasSubtreeSizes(): bool
    {
        return $this->subtree_sizes !== [];
    }

    public function getNodeSizesSum(): int
    {
        return array_sum($this->node_sizes);
    }

    /** @return iterable<int, int> node_id => size */
    public function iterateNodeSizes(): iterable
    {
        return $this->node_sizes;
    }

    /** @return iterable<int, int> node_id => subtree_size */
    public function iterateSubtreeSizes(): iterable
    {
        return $this->subtree_sizes;
    }

    /** @return iterable<int, list<int>> child_id => [parent_id, ...] */
    public function iterateAllParents(): iterable
    {
        return $this->all_parents;
    }

    /** @return iterable<int, string> node_id => class_name */
    public function iterateNodeClasses(): iterable
    {
        return $this->node_classes;
    }

    /** @return iterable<int, int> node_id => scc_id */
    public function iterateNodeToScc(): iterable
    {
        return $this->node_to_scc;
    }

    /**
     * Whether this node is the canonical representative or has no duplicates.
     */
    public function isCanonicalOrUnique(int $nodeId): bool
    {
        if (!isset($this->canonical[$nodeId])) {
            return true;
        }
        return $this->findCanonical($nodeId) === $nodeId;
    }

    /**
     * Get the canonical node_id for a given node.
     * Returns the node itself if it has no duplicates.
     */
    public function getCanonical(int $nodeId): int
    {
        return $this->findCanonical($nodeId);
    }

    /**
     * Get all original node_ids that share the same canonical.
     * Returns [$nodeId] if no duplicates.
     * @return list<int>
     */
    public function getCanonicalGroup(int $nodeId): array
    {
        $canon = $this->findCanonical($nodeId);
        return $this->canonicalToOriginals[$canon] ?? [$canon];
    }

    protected function findCanonical(int $node): int
    {
        if (!isset($this->canonical[$node])) {
            return $node;
        }
        // Path compression
        while ($this->canonical[$node] !== $node) {
            $this->canonical[$node] = $this->canonical[$this->canonical[$node]] ?? $this->canonical[$node];
            $node = $this->canonical[$node];
        }
        return $node;
    }

    /**
     * Load canonical_node_id from DB and build Union-Find structure.
     *
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument
     */
    protected function loadAddressMapping(\PDO $db, int $run_id): void
    {
        $stmt = $db->query(
            "SELECT node_id, canonical_node_id"
            . " FROM context_nodes"
            . " WHERE run_id = {$run_id} AND canonical_node_id IS NOT NULL"
        );

        if (!$stmt) {
            return;
        }

        while ($r = $stmt->fetch(\PDO::FETCH_NUM)) {
            $node_id = (int)$r[0];
            $canon = (int)$r[1];
            $this->canonical[$node_id] = $canon;
            $this->canonical[$canon] = $canon;
        }

        // Build reverse mapping: canonical → [original_node_ids]
        $this->canonicalToOriginals = [];
        foreach ($this->canonical as $node => $parent) {
            $canon = $this->findCanonical($node);
            $this->canonicalToOriginals[$canon][] = $node;
        }
        // Deduplicate
        foreach ($this->canonicalToOriginals as $canon => $nodes) {
            $this->canonicalToOriginals[$canon] = array_values(array_unique($nodes));
        }
    }

    /**
     * Build a unified adjacency list for SCC that maps through canonical IDs.
     */
    protected function buildSccAdjacency(): void
    {
        if ($this->canonical === []) {
            // No unification needed, SCC uses strong_all_children directly
            return;
        }

        foreach ($this->strong_all_children as $parent => $children) {
            $cp = $this->findCanonical($parent);
            foreach ($children as $child) {
                $cc = $this->findCanonical($child);
                if ($cp !== $cc) {
                    $this->scc_adjacency[$cp][] = $cc;
                }
            }
        }
        // Deduplicate
        foreach ($this->scc_adjacency as $node => $children) {
            $this->scc_adjacency[$node] = array_values(array_unique($children));
        }
    }

    /** @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument, MixedPropertyTypeCoercion */
    protected function loadNodeSizes(\PDO $db, int $run_id): void
    {
        $stmt = $db->query(
            "SELECT node_id, sum(size) as s, group_concat(DISTINCT class_name) as cls"
            . " FROM context_node_locations WHERE run_id = {$run_id} GROUP BY node_id"
        );

        while ($r = $stmt->fetch(\PDO::FETCH_NUM)) {
            $node_id = (int)$r[0];
            $this->node_sizes[$node_id] = (int)$r[1];
            if ($r[2] !== null) {
                $this->node_classes[$node_id] = $r[2];
            }
        }
    }

    /**
     * Load context node types ("PhpReferenceContext", etc.) into
     * {@see self::$node_types}. Called from loadFromDb after sizes are
     * loaded so the path-walker passes can answer type lookups
     * directly from memory.
     *
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument
     */
    protected function loadNodeTypes(\PDO $db, int $run_id): void
    {
        $stmt = $db->query(
            "SELECT node_id, type FROM context_nodes WHERE run_id = {$run_id}"
        );
        while ($r = $stmt->fetch(\PDO::FETCH_NUM)) {
            $this->node_types[(int)$r[0]] = (string)$r[1];
        }
    }

    /** @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument */
    protected function loadEdges(\PDO $db, int $run_id): void
    {
        $stmt = $db->query(
            "SELECT parent_node_id, child_node_id, link_name, is_tree, strength"
            . " FROM context_edges WHERE run_id = {$run_id}"
        );

        $edge_count = 0;
        while ($r = $stmt->fetch(\PDO::FETCH_NUM)) {
            $edge_count++;
            $parent = $r[0] === null ? -1 : (int)$r[0];
            $child = (int)$r[1];
            $link_name = (string)$r[2];
            $is_tree = (int)$r[3];
            $strength = (string)($r[4] ?? 'strong');
            $is_strong = $strength === 'strong';

            if ($is_tree) {
                $this->children[$parent][] = $child;
                if ($is_strong) {
                    $this->strong_children[$parent][] = $child;
                }
                if ($parent === -1) {
                    $this->roots[] = $child;
                }
                // Tree edges are unique per child by definition, so a
                // direct write here is safe and gives report passes an
                // O(1) link_name lookup without any extra SQL.
                $this->tree_link_names[$child] = $link_name;
                $this->tree_parents[$child] = $parent;
            }
            if ($parent !== -1) {
                $this->all_children[$parent][] = $child;
                if ($is_strong) {
                    $this->strong_all_children[$parent][] = $child;
                }
            }
            $this->all_parents[$child][] = $parent;
        }
        $this->edge_count = $edge_count;
    }

    /**
     * Resolve the tree-edge link_name for a child node.
     *
     * Returns null when the node has no tree edge in the loaded run.
     * O(1) — backed by the {@see self::$tree_link_names} index built
     * during {@see self::loadEdges()}.
     */
    public function getTreeLinkName(int $child_node_id): ?string
    {
        return $this->tree_link_names[$child_node_id] ?? null;
    }

    /**
     * Resolve the tree-edge parent for a child node, or null when
     * there is no parent (root) or no tree edge for this child.
     */
    public function getTreeParentNodeId(int $child_node_id): ?int
    {
        if (!isset($this->tree_parents[$child_node_id])) {
            return null;
        }
        $parent = $this->tree_parents[$child_node_id];
        return $parent === -1 ? null : $parent;
    }

    /**
     * Whether the substrate carries a tree-edge link_name index that
     * report passes can use instead of querying the DB themselves.
     */
    public function hasTreeLinkIndex(): bool
    {
        return $this->tree_link_names !== [];
    }

    /**
     * Yield every child_node_id whose tree-edge link_name equals
     * `$link_name`. Lets passes that used to issue
     *
     *   SELECT child_node_id FROM context_edges
     *   WHERE is_tree = 1 AND link_name = ?
     *
     * walk the in-memory tree link index instead. Order is unspecified.
     *
     * @return iterable<int>
     */
    public function iterateTreeChildrenByLinkName(string $link_name): iterable
    {
        foreach ($this->tree_link_names as $child_id => $name) {
            if ($name === $link_name) {
                yield $child_id;
            }
        }
    }

    protected function computeSubtreeSizes(): void
    {
        $stack = [];
        foreach ($this->roots as $root) {
            $stack[] = [$root, false];
        }

        while ($stack) {
            [$node, $processed] = array_pop($stack);
            if ($processed) {
                $size = $this->node_sizes[$node] ?? 0;
                foreach ($this->strong_children[$node] ?? [] as $child) {
                    $size += $this->subtree_sizes[$child] ?? 0;
                }
                $this->subtree_sizes[$node] = $size;
            } else {
                $stack[] = [$node, true];
                foreach ($this->strong_children[$node] ?? [] as $child) {
                    if (!isset($this->subtree_sizes[$child])) {
                        $stack[] = [$child, false];
                    }
                }
            }
        }
    }

    /** @psalm-suppress PossiblyNullArrayOffset, MixedArgument, UnsupportedReferenceUsage */
    protected function computeScc(): void
    {
        $use_unified = $this->scc_adjacency !== [];

        // When using unified adjacency, iterate over canonical nodes only
        if ($use_unified) {
            $nodes_to_visit = array_unique(array_map(
                fn (int $n): int => $this->findCanonical($n),
                array_keys($this->node_sizes)
            ));
        } else {
            $nodes_to_visit = array_keys($this->node_sizes);
        }

        $index_counter = 0;
        $stack = [];
        $on_stack = [];
        $index = [];
        $lowlink = [];
        $sccs = [];

        foreach ($nodes_to_visit as $v) {
            if (isset($index[$v])) {
                continue;
            }

            $call_stack = [[$v, 0]];
            $index[$v] = $lowlink[$v] = $index_counter++;
            $stack[] = $v;
            $on_stack[$v] = true;

            while ($call_stack) {
                [$node, $ci] = array_pop($call_stack);
                $node_children = $use_unified
                    ? ($this->scc_adjacency[$node] ?? [])
                    : ($this->strong_all_children[$node] ?? []);

                $found_unvisited = false;
                $count = count($node_children);
                for ($i = $ci; $i < $count; $i++) {
                    $w = $node_children[$i];
                    if (!isset($index[$w])) {
                        $call_stack[] = [$node, $i + 1];
                        $index[$w] = $lowlink[$w] = $index_counter++;
                        $stack[] = $w;
                        $on_stack[$w] = true;
                        $call_stack[] = [$w, 0];
                        $found_unvisited = true;
                        break;
                    } elseif (isset($on_stack[$w])) {
                        $lowlink[$node] = min($lowlink[$node], $index[$w]);
                    }
                }

                if (!$found_unvisited) {
                    if ($lowlink[$node] === $index[$node]) {
                        /** @var list<int> $scc */
                        $scc = [];
                        do {
                            /** @var int $w */
                            $w = array_pop($stack);
                            unset($on_stack[$w]);
                            $scc[] = $w;
                        } while ($w !== $node);
                        if (count($scc) > 1) {
                            $sccs[] = $scc;
                        }
                    }
                    if ($call_stack) {
                        $parent_frame = &$call_stack[count($call_stack) - 1];
                        $lowlink[$parent_frame[0]] = min($lowlink[$parent_frame[0]], $lowlink[$node]);
                    }
                }
            }
        }

        // When using unified adjacency, expand canonical nodes back to all originals
        if ($use_unified) {
            foreach ($sccs as &$scc) {
                $expanded = [];
                foreach ($scc as $canonical) {
                    if (isset($this->canonicalToOriginals[$canonical])) {
                        foreach ($this->canonicalToOriginals[$canonical] as $orig) {
                            $expanded[] = $orig;
                        }
                    } else {
                        $expanded[] = $canonical;
                    }
                }
                $scc = array_values(array_unique($expanded));
            }
            unset($scc);
        }

        // Build SCC profiles
        foreach ($sccs as $scc_id => $scc) {
            $scc_set = array_flip($scc);
            $total_size = 0;
            $class_counts = [];

            foreach ($scc as $node) {
                $this->node_to_scc[$node] = $scc_id;
                $total_size += $this->node_sizes[$node] ?? 0;
                $cls = $this->node_classes[$node] ?? null;
                if ($cls !== null) {
                    $class_counts[$cls] = ($class_counts[$cls] ?? 0) + 1;
                }
            }

            $ext_in = 0;
            $ext_out = 0;
            foreach ($scc as $node) {
                foreach ($this->all_parents[$node] ?? [] as $parent) {
                    if (!isset($scc_set[$parent]) && $parent !== -1) {
                        $ext_in++;
                    }
                }
                foreach ($this->all_children[$node] ?? [] as $child) {
                    if (!isset($scc_set[$child])) {
                        $ext_out++;
                    }
                }
            }

            arsort($class_counts);
            $signature_parts = [];
            foreach ($class_counts as $cls => $cnt) {
                $signature_parts[] = "{$cls}:{$cnt}";
            }
            $signature = implode(', ', $signature_parts);

            $this->scc_profiles[] = [
                'id' => $scc_id,
                'nodes' => $scc,
                'node_count' => count($scc),
                'total_size' => $total_size,
                'ext_in' => $ext_in,
                'ext_out' => $ext_out,
                'class_counts' => $class_counts,
                'signature' => $signature,
                'single_owner_likelihood' => $ext_in <= 2 ? 'high' : ($ext_in <= 10 ? 'medium' : 'low'),
            ];
        }
    }
}
