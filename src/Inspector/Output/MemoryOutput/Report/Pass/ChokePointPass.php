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

use Reli\Inspector\Output\MemoryOutput\Report\Finding;
use Reli\Inspector\Output\MemoryOutput\Report\FindingConfidence;
use Reli\Inspector\Output\MemoryOutput\Report\FindingSeverity;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\GraphSubstrate;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\NodeLabeler;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\PathFormatter;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\SizeFormatter;

final class ChokePointPass implements PassInterface
{
    public function __construct(
        private GraphSubstrate $substrate,
        private \PDO $db,
        private int $run_id,
        private int $heap_usage = 0,
    ) {
    }

    /**
     * @return list<Finding>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument, MixedOperand, MixedArgumentTypeCoercion
     * @psalm-suppress InvalidOperand, PossiblyInvalidArgument, RiskyTruthyFalsyComparison, MixedArrayOffset
     * @psalm-suppress RedundantCastGivenDocblockType
     */
    #[\Override]
    public function analyze(): array
    {
        $chokepoints = [];

        // Identify objects_store node IDs — used to deprioritize, not exclude.
        $objects_store_nodes = [];
        $os_stmt = $this->db->query(
            "SELECT DISTINCT node_id FROM context_node_locations"
            . " WHERE run_id = {$this->run_id}"
            . " AND location_type = 'ObjectsStoreMemoryLocation'"
        );
        while ($nid = $os_stmt->fetchColumn()) {
            $objects_store_nodes[(int)$nid] = true;
        }

        foreach ($this->substrate->iterateSubtreeSizes() as $node => $subtree) {
            // Skip non-canonical duplicates to avoid double-counting
            if (!$this->substrate->isCanonicalOrUnique($node)) {
                continue;
            }
            $shallow = $this->substrate->getNodeSize($node);
            if ($subtree < 1024 * 1024) {
                continue;
            }
            $effective_shallow = max($shallow, 1);
            $ratio = $subtree / $effective_shallow;
            if ($ratio > 10) {
                $chokepoints[] = [$node, $shallow, $subtree, $ratio];
            }
        }
        // Sort by subtree size descending, but demote objects_store nodes
        // to the end — they are trivially large and rarely actionable.
        usort($chokepoints, function ($a, $b) use ($objects_store_nodes) {
            $a_is_os = isset($objects_store_nodes[$a[0]]);
            $b_is_os = isset($objects_store_nodes[$b[0]]);
            if ($a_is_os !== $b_is_os) {
                return $a_is_os ? 1 : -1;
            }
            return $b[2] <=> $a[2];
        });

        if ($chokepoints === []) {
            return [];
        }

        // Filter out chain redundancy: if a node's tree parent is also
        // a choke_point candidate, suppress the parent (keep the deeper one).
        // This avoids listing every node on the same bottleneck path.
        $candidate_set = [];
        foreach ($chokepoints as $cp) {
            $candidate_set[$cp[0]] = true;
        }
        $filtered = [];
        foreach ($chokepoints as $cp) {
            $has_child_candidate = false;
            foreach ($this->substrate->getChildren($cp[0]) as $child) {
                if (isset($candidate_set[$child])) {
                    $has_child_candidate = true;
                    break;
                }
            }
            if (!$has_child_candidate) {
                $filtered[] = $cp;
            }
        }
        $chokepoints = $filtered;

        // On-demand path lookup (max ~20 rows per choke_point × 10 = 200 queries)
        $parent_stmt = $this->db->prepare(
            "SELECT parent_node_id, link_name FROM context_edges"
            . " WHERE child_node_id = ? AND is_tree = 1"
            . " AND run_id = {$this->run_id} LIMIT 1"
        );
        $ntype_stmt = $this->db->prepare(
            "SELECT type FROM context_nodes"
            . " WHERE node_id = ? AND run_id = {$this->run_id} LIMIT 1"
        );

        $labeler = new NodeLabeler($this->db, $this->run_id);

        $loc_stmt = $this->db->prepare(
            "SELECT class_name, location_type FROM context_node_locations"
            . " WHERE node_id = ? AND run_id = {$this->run_id} LIMIT 1"
        );
        $type_stmt = $this->db->prepare(
            "SELECT type FROM context_nodes"
            . " WHERE node_id = ? AND run_id = {$this->run_id} LIMIT 1"
        );

        $findings = [];
        foreach (array_slice($chokepoints, 0, 10) as [$node, $shallow, $subtree, $ratio]) {
            $loc_stmt->execute([$node]);
            $loc = $loc_stmt->fetch(\PDO::FETCH_ASSOC);
            $class = $loc['class_name'] ?? '';
            $loc_type = $loc['location_type'] ?? '';

            // Build a meaningful label: prefer class_name > location_type > node type > link_name
            $label = '';
            if ($class) {
                $label = $class;
            } elseif ($loc_type !== '') {
                $label = $loc_type;
            } else {
                $type_stmt->execute([$node]);
                $type_row = $type_stmt->fetch(\PDO::FETCH_NUM);
                if ($type_row) {
                    $label = $type_row[0];
                }
            }

            // Walk up to root for full PHP-syntax path.
            // If the path goes through objects_store, try to find an
            // alternative application-level parent (global_variables,
            // call_frames, etc.) that reaches the same object.
            $up_parts = [];
            $up_types = [];
            $cur = $node;
            for ($i = 0; $i < 20; $i++) {
                $parent_stmt->execute([$cur]);
                $pr = $parent_stmt->fetch(\PDO::FETCH_NUM);
                if (!$pr) {
                    break;
                }
                if ($pr[0] === null) {
                    // Reached root — record the root link_name and stop
                    array_unshift($up_parts, (string)$pr[1]);
                    array_unshift($up_types, '');
                    break;
                }
                $parent = (int)$pr[0];
                $link = (string)$pr[1];

                // If the parent is objects_store, try to find a better path
                if (isset($objects_store_nodes[$parent])) {
                    $alt = $this->findAlternativeTreeParent(
                        $cur,
                        $objects_store_nodes,
                    );
                    if ($alt !== null) {
                        [$parent, $link] = $alt;
                    }
                }

                $resolved = $labeler->resolvePathLabel(
                    (string)$link,
                    $cur
                );
                array_unshift($up_parts, $resolved);

                $ntype_stmt->execute([$cur]);
                $nt = $ntype_stmt->fetchColumn();
                array_unshift($up_types, $nt !== false ? (string)$nt : '');
                if ($label === '') {
                    $label = (string)$link;
                }
                $cur = $parent;
            }
            if ($label === '') {
                $label = "(node #{$node})";
            }
            $path = $up_parts !== []
                ? PathFormatter::toPhpSyntax($up_parts, $up_types)
                : '(root)';

            $n_children = count($this->substrate->getChildren($node));

            $heap = max($this->heap_usage, 1);
            $pct = $subtree / $heap * 100.0;
            $severity = match (true) {
                $pct > 30.0 => FindingSeverity::High,
                $pct > 10.0 => FindingSeverity::Medium,
                default => FindingSeverity::Low,
            };

            $findings[] = new Finding(
                kind: 'choke_point',
                severity: $severity,
                confidence: FindingConfidence::High,
                summary: sprintf(
                    '%s (%s shallow) holds %s via %d children — %s',
                    $label,
                    SizeFormatter::format($shallow),
                    SizeFormatter::format($subtree),
                    $n_children,
                    $path,
                ),
                facts: [
                    'node_id' => $node,
                    'class_or_type' => $label,
                    'shallow_size' => $shallow,
                    'subtree_size' => $subtree,
                    'ratio' => round($ratio, 0),
                    'children_count' => $n_children,
                    'path' => $path,
                ],
                hypothesis: 'Small object retaining a large subtree — gateway to large memory',
                next_checks: [
                    'Releasing this object would free ' . round($subtree / 1024 / 1024, 2) . ' MB',
                    'Check if this is a container that can be bounded or streamed',
                ],
                impact_bytes: $subtree,
                evidence_node_ids: [$node],
            );
        }

        return $findings;
    }

    /**
     * Find an alternative parent for a node that avoids objects_store.
     *
     * In streaming mode, objects_store is traversed first so its edges
     * get is_tree=1, while app-level edges (global_variables, call_frames)
     * arrive later as is_tree=0. Therefore we search ALL edges, not just
     * tree edges.
     *
     * @param array<int, true> $objects_store_nodes
     * @param array<int, array{int, string}> $parent_map tree parent_map
     * @return array{int, string}|null [parent_node_id, link_name] or null
     */
    private function findAlternativeTreeParent(
        int $node,
        array $objects_store_nodes,
    ): ?array {
        foreach ($this->substrate->getAllParents($node) as $alt_parent) {
            if ($alt_parent < 0) {
                continue;
            }
            if (isset($objects_store_nodes[$alt_parent])) {
                continue;
            }
            // Found a non-objects_store parent; look up any edge link_name
            $stmt = $this->db->prepare(
                "SELECT link_name FROM context_edges"
                . " WHERE run_id = {$this->run_id}"
                . " AND parent_node_id = ? AND child_node_id = ?"
                . " LIMIT 1"
            );
            $stmt->execute([$alt_parent, $node]);
            $link = $stmt->fetchColumn();
            if ($link !== false) {
                return [$alt_parent, (string)$link];
            }
        }
        return null;
    }
}
