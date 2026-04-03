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
     * @psalm-suppress InvalidOperand, PossiblyInvalidArgument, RiskyTruthyFalsyComparison
     */
    #[\Override]
    public function analyze(): array
    {
        $chokepoints = [];
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
        usort($chokepoints, fn($a, $b) => $b[2] <=> $a[2]);

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

        // Build parent map and node type map for path lookup
        $parent_map = [];
        $rows = $this->db->query(
            "SELECT child_node_id, parent_node_id, link_name FROM context_edges"
            . " WHERE is_tree = 1 AND run_id = {$this->run_id}"
        )->fetchAll(\PDO::FETCH_NUM);
        foreach ($rows as $r) {
            $parent_map[(int)$r[0]] = [(int)$r[1], $r[2]];
        }
        unset($rows);

        /** @var array<int, string> */
        $node_type_map = [];
        $rows = $this->db->query(
            "SELECT node_id, type FROM context_nodes"
            . " WHERE run_id = {$this->run_id}"
        )->fetchAll(\PDO::FETCH_NUM);
        foreach ($rows as $r) {
            $node_type_map[(int)$r[0]] = (string)$r[1];
        }
        unset($rows);

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

            // Walk up to root for full PHP-syntax path
            $up_parts = [];
            $up_types = [];
            $cur = $node;
            for ($i = 0; $i < 20; $i++) {
                if (!isset($parent_map[$cur])) {
                    break;
                }
                [$parent, $link] = $parent_map[$cur];
                $resolved = $labeler->resolvePathLabel(
                    (string)$link,
                    $cur
                );
                array_unshift($up_parts, $resolved);
                array_unshift($up_types, $node_type_map[$cur] ?? '');
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
}
