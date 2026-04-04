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

final class CycleClusterPass implements PassInterface
{
    public function __construct(
        private GraphSubstrate $substrate,
        private \PDO $db,
        private int $run_id,
    ) {
    }

    /**
     * @return list<Finding>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument
     * @psalm-suppress MixedOperand, InvalidOperand
     * @psalm-suppress PossiblyInvalidArgument, InvalidArgument
     */
    #[\Override]
    public function analyze(): array
    {
        if ($this->substrate->getSccProfiles() === []) {
            return [];
        }

        $pattern_groups = [];
        foreach ($this->substrate->getSccProfiles() as $profile) {
            $pattern_groups[$profile['signature']][] = $profile;
        }

        $groups_sorted = [];
        foreach ($pattern_groups as $sig => $group) {
            $total = 0;
            foreach ($group as $profile) {
                $total += $profile['total_size'];
            }
            $groups_sorted[] = [
                'sig' => $sig,
                'group' => $group,
                'total' => $total,
            ];
        }
        usort($groups_sorted, fn($a, $b) => $b['total'] <=> $a['total']);

        // Load link_names and node types for path resolution
        $link_names = $this->loadLinkNames();
        $labeler = new NodeLabeler($this->db, $this->run_id);

        // Load node types for PathFormatter
        $node_type_map = $this->loadNodeTypes();

        // Load parent map for entry point path
        $parent_map = $this->loadParentMap();

        $findings = [];
        foreach (array_slice($groups_sorted, 0, 10) as $g) {
            $group = $g['group'];
            $example = $group[0];
            $count = count($group);
            $class_count = count($example['class_counts']);

            $composition = $this->formatComposition(
                $example['class_counts']
            );

            // Find back-reference (non-tree edge within SCC)
            $back_ref = $this->findBackReference(
                $example['nodes'],
                $link_names,
            );

            // Compute retained size (shallow + downstream)
            $retained = $this->computeRetained($example['nodes']);

            // Find entry point path
            $entry_path = $this->findEntryPath(
                $example['nodes'],
                $parent_map,
                $node_type_map,
                $labeler,
            );

            // Micro-cycles (2 nodes)
            if ($example['node_count'] === 2) {
                $findings[] = new Finding(
                    kind: 'micro_cycle',
                    severity: FindingSeverity::Low,
                    confidence: FindingConfidence::High,
                    summary: sprintf(
                        '%s micro-cycle%s: %s (%s total)',
                        number_format($count),
                        $count > 1 ? 's' : '',
                        $composition,
                        SizeFormatter::format($g['total']),
                    ),
                    facts: [
                        'composition' => $composition,
                        'count' => $count,
                        'class_count' => $class_count,
                        'total_size' => $g['total'],
                        'back_reference' => $back_ref,
                    ],
                    hypothesis: 'Bidirectional references'
                        . ' between two objects'
                        . ($back_ref !== ''
                            ? "\nBack-reference: {$back_ref}"
                            : ''),
                    next_checks: [
                        'Check if back-reference can use WeakReference',
                    ],
                    impact_bytes: $retained * $count,
                );
                continue;
            }

            // Large DI-container-like cycles
            if ($class_count > 15 && $count <= 2) {
                $findings[] = new Finding(
                    kind: 'di_container_cycle',
                    severity: FindingSeverity::Info,
                    confidence: FindingConfidence::High,
                    summary: sprintf(
                        '%d classes forming %d cycle%s (%s)',
                        $class_count,
                        $count,
                        $count > 1 ? 's' : '',
                        SizeFormatter::format($g['total']),
                    ),
                    facts: [
                        'composition' => $composition,
                        'count' => $count,
                        'class_count' => $class_count,
                        'total_size' => $g['total'],
                    ],
                    hypothesis: 'Structural cost of DI container.'
                        . "\nTop classes: {$composition}",
                    next_checks: [
                        'Reducing container instances'
                        . ' (workers/tests) reduces this',
                    ],
                    impact_bytes: $g['total'],
                );
                continue;
            }

            // Normal cycle clusters
            $hypothesis_parts = ["Per cycle: {$composition}"];
            if ($back_ref !== '') {
                $hypothesis_parts[] = "Back-reference: {$back_ref}";
            }
            if ($entry_path !== '') {
                $hypothesis_parts[] = "Example: {$entry_path}";
                if ($count > 1) {
                    $more = $count - 1;
                    $hypothesis_parts[] = "({$more} more with same pattern)";
                }
            }
            if ($example['single_owner_likelihood'] === 'high') {
                $hypothesis_parts[] = 'Single entry point — breaking'
                    . ' the back-reference likely frees this cycle';
            } elseif ($count > 10) {
                $hypothesis_parts[] = "{$count} identical cycles"
                    . ' — a structural pattern';
            }

            $retained_label = $retained > $example['total_size']
                ? sprintf(
                    ', %s retained',
                    SizeFormatter::format($retained * $count)
                )
                : '';

            $findings[] = new Finding(
                kind: 'cycle_cluster',
                severity: $g['total'] > 1024 * 100
                    ? FindingSeverity::Medium
                    : FindingSeverity::Low,
                confidence: FindingConfidence::High,
                summary: sprintf(
                    '%d identical cycle%s (%d classes, %s shallow%s)',
                    $count,
                    $count > 1 ? 's' : '',
                    $class_count,
                    SizeFormatter::format($g['total']),
                    $retained_label,
                ),
                facts: [
                    'composition' => $composition,
                    'count' => $count,
                    'class_count' => $class_count,
                    'total_size' => $g['total'],
                    'retained_size' => $retained * $count,
                    'back_reference' => $back_ref,
                    'entry_path' => $entry_path,
                    'ext_incoming' => $example['ext_in'],
                    'single_owner_likelihood' => $example['single_owner_likelihood'],
                ],
                hypothesis: implode("\n", $hypothesis_parts),
                next_checks: $back_ref !== ''
                    ? [
                        "Break {$back_ref} to eliminate all {$count} cycles",
                        'Consider using WeakReference or explicit cleanup',
                    ]
                    : [
                        'Identify the back-reference causing the cycle',
                        'Consider using WeakReference or explicit cleanup',
                    ],
                impact_bytes: $retained * $count,
                evidence_node_ids: array_slice($example['nodes'], 0, 5),
            );
        }

        // Check for resource-holding classes downstream of SCCs
        $resource_findings = $this->detectResourceLeakRisks();
        $findings = array_merge($findings, $resource_findings);

        return $findings;
    }

    /**
     * Detect PDO/PDOStatement/Mysqli downstream of SCCs that are GC candidates.
     *
     * Only reports when the SCC is reachable solely via objects_store (weak
     * references). Cycles that are strongly rooted — such as those formed by
     * DI containers in frameworks like Laravel — are excluded because the
     * resource's lifetime is governed by the application, not the GC.
     *
     * @return list<Finding>
     */
    private function detectResourceLeakRisks(): array
    {
        $resource_classes = [
            'PDO', 'PDOStatement',
            'Mysqli', 'mysqli', 'mysqli_stmt', 'mysqli_result',
        ];

        // Build root-ownership map (same approach as GcPendingPass)
        $gc_only_scc_ids = $this->findGcOnlySccIds();
        if ($gc_only_scc_ids === []) {
            return [];
        }

        $findings = [];
        $seen_risks = [];

        foreach ($this->substrate->getSccProfiles() as $profile) {
            // Skip SCCs that are strongly rooted (not GC candidates)
            if (!isset($gc_only_scc_ids[$profile['id']])) {
                continue;
            }

            $scc_set = array_flip($profile['nodes']);

            // Check ext_outgoing: strong children of SCC nodes that are outside SCC
            foreach ($profile['nodes'] as $node) {
                foreach ($this->substrate->getStrongChildren($node) as $child) {
                    if (isset($scc_set[$child])) {
                        continue;
                    }
                    $this->checkDownstreamForResources(
                        $child,
                        $resource_classes,
                        $profile,
                        $seen_risks,
                        $findings,
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * Find SCC IDs where ALL member nodes are owned only by objects_store.
     *
     * @return array<int, true> scc_id => true
     * @psalm-suppress MixedArrayAccess, MixedAssignment
     */
    private function findGcOnlySccIds(): array
    {
        // BFS from roots via tree edges to determine root ownership
        $root_link_names = $this->loadRootLinkNames();
        $node_root_owner = [];

        foreach ($this->substrate->getRoots() as $root) {
            $queue = [$root];
            $qi = 0;
            $node_root_owner[$root] = $root;
            while ($qi < count($queue)) {
                $node = $queue[$qi++];
                foreach ($this->substrate->getChildren($node) as $child) {
                    if (!isset($node_root_owner[$child])) {
                        $node_root_owner[$child] = $root;
                        $queue[] = $child;
                    }
                }
            }
        }

        // Find objects_store root
        $objects_store_root = null;
        foreach ($this->substrate->getRoots() as $root) {
            if (($root_link_names[$root] ?? '') === 'objects_store') {
                $objects_store_root = $root;
                break;
            }
        }

        if ($objects_store_root === null) {
            return [];
        }

        // Collect SCCs where ALL members are owned by objects_store
        $gc_only_scc_ids = [];
        foreach ($this->substrate->getSccProfiles() as $profile) {
            $all_gc = true;
            foreach ($profile['nodes'] as $node) {
                $owner = $node_root_owner[$node] ?? null;
                if ($owner !== $objects_store_root) {
                    $all_gc = false;
                    break;
                }
            }
            if ($all_gc) {
                $gc_only_scc_ids[$profile['id']] = true;
            }
        }

        return $gc_only_scc_ids;
    }

    /**
     * @return array<int, string> root_node_id => link_name
     * @psalm-suppress MixedArrayAccess, MixedAssignment
     */
    private function loadRootLinkNames(): array
    {
        $stmt = $this->db->query(
            "SELECT child_node_id, link_name FROM context_edges"
            . " WHERE parent_node_id IS NULL AND is_tree = 1"
            . " AND run_id = {$this->run_id}"
        );

        $map = [];
        while ($r = $stmt->fetch(\PDO::FETCH_NUM)) {
            $map[(int)$r[0]] = (string)$r[1];
        }
        return $map;
    }

    /**
     * Walk downstream from a node looking for resource-holding classes.
     * @param list<string> $resource_classes
     * @param array<string, mixed> $profile
     * @param array<string, true> $seen_risks
     * @param list<Finding> $findings
     * @psalm-suppress MixedArgument, MixedArrayAccess
     */
    private function checkDownstreamForResources(
        int $start_node,
        array $resource_classes,
        array $profile,
        array &$seen_risks,
        array &$findings,
    ): void {
        // BFS limited depth
        $queue = [$start_node];
        $qi = 0;
        $visited = [];
        while ($qi < count($queue) && $qi < 50) {
            $node = $queue[$qi++];
            if (isset($visited[$node])) {
                continue;
            }
            $visited[$node] = true;

            $cls = $this->substrate->getNodeClass($node);
            if ($cls !== null) {
                foreach ($resource_classes as $rc) {
                    if ($cls === $rc || str_ends_with($cls, "\\{$rc}")) {
                        $key = $cls . ':' . (string)$profile['signature'];
                        if (isset($seen_risks[$key])) {
                            continue;
                        }
                        $seen_risks[$key] = true;

                        /** @var array<string, int> $cc */
                        $cc = $profile['class_counts'];
                        $scc_classes = implode(
                            ', ',
                            array_keys($cc)
                        );

                        $findings[] = new Finding(
                            kind: 'resource_leak_risk',
                            severity: FindingSeverity::High,
                            confidence: FindingConfidence::Medium,
                            summary: sprintf(
                                '%s held by cycle (%s)',
                                $cls,
                                $scc_classes,
                            ),
                            facts: [
                                'resource_class' => $cls,
                                'scc_classes' => $scc_classes,
                                'scc_signature' => $profile['signature'],
                            ],
                            hypothesis: sprintf(
                                'Circular reference prevents timely'
                                . " destruction of %s.\n"
                                . 'Connection/resource not released'
                                . " until GC cycle collection.\n"
                                . 'With persistent connections, GC'
                                . ' may trigger unexpected rollback.',
                                $cls
                            ),
                            next_checks: [
                                'Break the circular reference to ensure'
                                . ' deterministic resource cleanup',
                                'Especially dangerous in long-running'
                                . ' workers (connection pool exhaustion)',
                            ],
                        );
                    }
                }
            }

            // Continue BFS via strong edges only
            foreach ($this->substrate->getStrongChildren($node) as $child) {
                if (!isset($visited[$child])) {
                    $queue[] = $child;
                }
            }
        }
    }

    /**
     * Find the non-tree edge within an SCC (the back-reference).
     * @param list<int> $nodes
     * @param array<int, string> $link_names
     */
    private function findBackReference(
        array $nodes,
        array $link_names,
    ): string {
        $scc_set = array_flip($nodes);

        foreach ($nodes as $node) {
            // Check all_children for edges that go to another SCC member
            foreach ($this->substrate->getAllChildren($node) as $child) {
                if (!isset($scc_set[$child])) {
                    continue;
                }
                // Is this a non-tree edge? (tree edge would be in $children)
                $is_tree = in_array(
                    $child,
                    $this->substrate->getChildren($node),
                    true
                );
                if ($is_tree) {
                    continue;
                }

                // Found a non-tree edge within SCC
                $link = $link_names[$child] ?? '?';
                $src_class = $this->substrate->getNodeClass($node);
                $tgt_class = $this->substrate->getNodeClass($child);

                if ($src_class !== null && $tgt_class !== null) {
                    return "{$src_class}::\${$link} -> {$tgt_class}";
                }
                if ($src_class !== null) {
                    return "{$src_class}::\${$link}";
                }
                return $link;
            }
        }

        return '';
    }

    /**
     * Compute retained size for an SCC: shallow + downstream.
     * @param list<int> $nodes
     */
    private function computeRetained(array $nodes): int
    {
        $scc_set = array_flip($nodes);
        $shallow_total = 0;
        $downstream = 0;

        foreach ($nodes as $node) {
            $shallow_total += $this->substrate->getNodeSize($node);


            // Add downstream retained from strong tree children outside SCC
            foreach ($this->substrate->getStrongChildren($node) as $child) {
                if (!isset($scc_set[$child])) {
                    $downstream
                        += $this->substrate->getSubtreeSize($child);
                }
            }
        }

        return $shallow_total + $downstream;
    }

    /**
     * Find entry point path to an SCC member.
     * @param list<int> $nodes
     * @param array<int, array{0: int, 1: string}> $parent_map
     * @param array<int, string> $node_type_map
     */
    private function findEntryPath(
        array $nodes,
        array $parent_map,
        array $node_type_map,
        NodeLabeler $labeler,
    ): string {
        $scc_set = array_flip($nodes);

        // Find an SCC node that has an external parent (entry point)
        $entry_node = null;
        foreach ($nodes as $node) {
            foreach ($this->substrate->getAllParents($node) as $parent) {
                if ($parent !== -1 && !isset($scc_set[$parent])) {
                    $entry_node = $node;
                    break 2;
                }
            }
        }

        if ($entry_node === null) {
            return '';
        }

        // Walk up from entry_node to root
        $parts = [];
        $types = [];
        $cur = $entry_node;
        for ($i = 0; $i < 20; $i++) {
            if (!isset($parent_map[$cur])) {
                break;
            }
            [$parent, $link] = $parent_map[$cur];
            $resolved = $labeler->resolvePathLabel($link, $cur);
            array_unshift($parts, $resolved);
            array_unshift($types, $node_type_map[$cur] ?? '');
            $cur = $parent;
        }

        return $parts !== []
            ? PathFormatter::toPhpSyntax($parts, $types)
            : '';
    }

    /**
     * @param array<string, int> $class_counts
     */
    private function formatComposition(array $class_counts): string
    {
        $parts = [];
        $i = 0;
        foreach ($class_counts as $cls => $cnt) {
            if ($i >= 5) {
                $remaining = count($class_counts) - 5;
                $parts[] = "... +{$remaining} more";
                break;
            }
            $parts[] = "{$cnt}x {$cls}";
            $i++;
        }
        return implode(' + ', $parts);
    }

    /**
     * @return array<int, string>
     * @psalm-suppress MixedArrayAccess, MixedAssignment
     */
    private function loadLinkNames(): array
    {
        $stmt = $this->db->query(
            "SELECT child_node_id, link_name FROM context_edges"
            . " WHERE is_tree = 1 AND run_id = {$this->run_id}"
        );

        $map = [];
        while ($r = $stmt->fetch(\PDO::FETCH_NUM)) {
            $map[(int)$r[0]] = (string)$r[1];
        }
        return $map;
    }

    /**
     * @return array<int, string>
     * @psalm-suppress MixedArrayAccess, MixedAssignment
     */
    private function loadNodeTypes(): array
    {
        $stmt = $this->db->query(
            "SELECT node_id, type FROM context_nodes"
            . " WHERE run_id = {$this->run_id}"
        );

        $map = [];
        while ($r = $stmt->fetch(\PDO::FETCH_NUM)) {
            $map[(int)$r[0]] = (string)$r[1];
        }
        return $map;
    }

    /**
     * @return array<int, array{0: int, 1: string}>
     * @psalm-suppress MixedArrayAccess, MixedAssignment
     */
    private function loadParentMap(): array
    {
        $stmt = $this->db->query(
            "SELECT child_node_id, parent_node_id, link_name"
            . " FROM context_edges WHERE is_tree = 1"
            . " AND run_id = {$this->run_id}"
        );

        $map = [];
        while ($r = $stmt->fetch(\PDO::FETCH_NUM)) {
            $map[(int)$r[0]] = [(int)($r[1] ?? -1), (string)$r[2]];
        }
        return $map;
    }
}
