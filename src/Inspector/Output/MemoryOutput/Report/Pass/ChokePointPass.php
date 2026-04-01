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

final class ChokePointPass implements PassInterface
{
    public function __construct(
        private GraphSubstrate $substrate,
        private \PDO $db,
        private int $run_id,
    ) {
    }

    /**
     * @return list<Finding>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument, MixedOperand, MixedArgumentTypeCoercion, InvalidOperand, PossiblyInvalidArgument, RiskyTruthyFalsyComparison
     */
    #[\Override]
    public function analyze(): array
    {
        $chokepoints = [];
        foreach ($this->substrate->subtree_sizes as $node => $subtree) {
            $shallow = $this->substrate->node_sizes[$node] ?? 0;
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

        // Build parent map for path lookup
        $parent_map = [];
        $rows = $this->db->query(
            "SELECT child_node_id, parent_node_id, link_name FROM context_edges"
            . " WHERE is_tree = 1 AND run_id = {$this->run_id}"
        )->fetchAll(\PDO::FETCH_NUM);
        foreach ($rows as $r) {
            $parent_map[(int)$r[0]] = [(int)$r[1], $r[2]];
        }
        unset($rows);

        $loc_stmt = $this->db->prepare(
            "SELECT class_name, location_type FROM context_node_locations"
            . " WHERE node_id = ? AND run_id = {$this->run_id} LIMIT 1"
        );

        $findings = [];
        foreach (array_slice($chokepoints, 0, 10) as [$node, $shallow, $subtree, $ratio]) {
            $loc_stmt->execute([$node]);
            $loc = $loc_stmt->fetch(\PDO::FETCH_ASSOC);
            $class = $loc['class_name'] ?? '';
            $loc_type = $loc['location_type'] ?? '';
            $label = $class ? preg_replace('/^.*\\\\/', '', $class) : $loc_type;

            // Walk up to build path
            $path_parts = [];
            $cur = $node;
            for ($i = 0; $i < 4; $i++) {
                if (!isset($parent_map[$cur])) {
                    break;
                }
                [$parent, $link] = $parent_map[$cur];
                $path_parts[] = $link;
                $cur = $parent;
            }
            $path = $path_parts ? implode(' <- ', $path_parts) : '(root)';

            $n_children = count($this->substrate->children[$node] ?? []);

            $findings[] = new Finding(
                kind: 'choke_point',
                severity: FindingSeverity::High,
                confidence: FindingConfidence::High,
                summary: sprintf(
                    '%s (%dB shallow) holds %.2f MB via %d children',
                    $label,
                    $shallow,
                    $subtree / 1024 / 1024,
                    $n_children,
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
