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

final class GraphSubstrate
{
    /** @var array<int, int> node_id => shallow size */
    public array $node_sizes = [];

    /** @var array<int, list<int>> parent_id => [child_id, ...] (tree edges only) */
    public array $children = [];

    /** @var array<int, list<int>> child_id => [parent_id, ...] (all edges) */
    public array $all_parents = [];

    /** @var list<int> root node IDs (parent_node_id IS NULL) */
    public array $roots = [];

    /** @var array<int, int> node_id => subtree size */
    public array $subtree_sizes = [];

    /** @var array<int, string> node_id => class name */
    public array $node_classes = [];

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

    public static function loadFromDb(\PDO $db, int $run_id): self
    {
        $substrate = new self();
        $substrate->loadNodeSizes($db, $run_id);
        $substrate->loadEdges($db, $run_id);
        $substrate->computeSubtreeSizes();
        $substrate->computeScc();
        return $substrate;
    }

    /** @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument, MixedPropertyTypeCoercion */
    private function loadNodeSizes(\PDO $db, int $run_id): void
    {
        $rows = $db->query(
            "SELECT node_id, sum(size) as s, group_concat(DISTINCT class_name) as cls"
            . " FROM context_node_locations WHERE run_id = {$run_id} GROUP BY node_id"
        )->fetchAll(\PDO::FETCH_NUM);

        foreach ($rows as $r) {
            $node_id = (int)$r[0];
            $this->node_sizes[$node_id] = (int)$r[1];
            if ($r[2] !== null) {
                $this->node_classes[$node_id] = $r[2];
            }
        }
        unset($rows);
    }

    /** @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument */
    private function loadEdges(\PDO $db, int $run_id): void
    {
        $rows = $db->query(
            "SELECT parent_node_id, child_node_id, is_tree"
            . " FROM context_edges WHERE run_id = {$run_id}"
        )->fetchAll(\PDO::FETCH_NUM);

        foreach ($rows as $r) {
            $parent = $r[0] === null ? -1 : (int)$r[0];
            $child = (int)$r[1];
            $is_tree = (int)$r[2];

            if ($is_tree) {
                $this->children[$parent][] = $child;
                if ($parent === -1) {
                    $this->roots[] = $child;
                }
            }
            $this->all_parents[$child][] = $parent;
        }
        $this->edge_count = count($rows);
        unset($rows);
    }

    private function computeSubtreeSizes(): void
    {
        $stack = [];
        foreach ($this->roots as $root) {
            $stack[] = [$root, false];
        }

        while ($stack) {
            [$node, $processed] = array_pop($stack);
            if ($processed) {
                $size = $this->node_sizes[$node] ?? 0;
                foreach ($this->children[$node] ?? [] as $child) {
                    $size += $this->subtree_sizes[$child] ?? 0;
                }
                $this->subtree_sizes[$node] = $size;
            } else {
                $stack[] = [$node, true];
                foreach ($this->children[$node] ?? [] as $child) {
                    if (!isset($this->subtree_sizes[$child])) {
                        $stack[] = [$child, false];
                    }
                }
            }
        }
    }

    /** @psalm-suppress PossiblyNullArrayOffset, MixedArgument, UnsupportedReferenceUsage */
    private function computeScc(): void
    {
        $index_counter = 0;
        $stack = [];
        $on_stack = [];
        $index = [];
        $lowlink = [];
        $sccs = [];

        foreach (array_keys($this->node_sizes) as $v) {
            if (isset($index[$v])) {
                continue;
            }

            $call_stack = [[$v, 0]];
            $index[$v] = $lowlink[$v] = $index_counter++;
            $stack[] = $v;
            $on_stack[$v] = true;

            while ($call_stack) {
                [$node, $ci] = array_pop($call_stack);
                $node_children = $this->children[$node] ?? [];

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
                foreach ($this->children[$node] ?? [] as $child) {
                    if (!isset($scc_set[$child])) {
                        $ext_out++;
                    }
                }
            }

            arsort($class_counts);
            $signature_parts = [];
            foreach ($class_counts as $cls => $cnt) {
                $short = preg_replace('/^.*\\\\/', '', $cls);
                $signature_parts[] = "{$short}:{$cnt}";
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
