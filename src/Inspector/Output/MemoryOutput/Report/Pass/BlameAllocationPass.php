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
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\SizeFormatter;

final class BlameAllocationPass implements PassInterface
{
    public function __construct(
        private GraphSubstrate $substrate,
        private \PDO $db,
        private int $run_id,
    ) {
    }

    /**
     * @return list<Finding>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument, MixedOperand, InvalidOperand
     * @psalm-suppress RedundantCastGivenDocblockType
     */
    #[\Override]
    public function analyze(): array
    {
        // Assign each node to its root owner via BFS
        $node_root_owner = [];
        $root_link_names = [];

        $link_stmt = $this->db->prepare(
            "SELECT link_name FROM context_edges WHERE child_node_id = ?"
            . " AND is_tree = 1 AND parent_node_id IS NULL AND run_id = {$this->run_id} LIMIT 1"
        );

        foreach ($this->substrate->roots as $root) {
            $link_stmt->execute([$root]);
            /** @var array{0: string}|false $r */
            $r = $link_stmt->fetch(\PDO::FETCH_NUM);
            $root_link_names[$root] = $r !== false ? (string)$r[0] : "root_{$root}";

            $queue = [$root];
            $qi = 0;
            $node_root_owner[$root] = $root;
            while ($qi < count($queue)) {
                $node = $queue[$qi++];
                foreach ($this->substrate->children[$node] ?? [] as $child) {
                    if (!isset($node_root_owner[$child])) {
                        $node_root_owner[$child] = $root;
                        $queue[] = $child;
                    }
                }
            }
        }

        // Compute incoming count per node
        $incoming_count = [];
        foreach ($this->substrate->all_parents as $child => $parents) {
            $incoming_count[$child] = count($parents);
        }

        // Compute blame
        $blame = [];
        foreach ($this->substrate->roots as $root) {
            $blame[$root] = ['exclusive' => 0, 'shared' => 0, 'nodes' => 0];
        }

        foreach ($this->substrate->node_sizes as $node => $size) {
            if ($size === 0) {
                continue;
            }
            $owner = $node_root_owner[$node] ?? null;
            if ($owner === null) {
                continue;
            }

            $in_count = $incoming_count[$node] ?? 1;

            if ($in_count <= 1) {
                $blame[$owner]['exclusive'] += $size;
                $blame[$owner]['nodes']++;
            } else {
                $owner_shares = [];
                foreach ($this->substrate->all_parents[$node] ?? [] as $parent) {
                    if ($parent === -1) {
                        continue;
                    }
                    $parent_owner = $node_root_owner[$parent] ?? null;
                    if ($parent_owner !== null) {
                        $owner_shares[$parent_owner] = ($owner_shares[$parent_owner] ?? 0) + 1;
                    }
                }
                $total_shares = array_sum($owner_shares);
                if ($total_shares === 0) {
                    $blame[$owner]['exclusive'] += $size;
                } else {
                    foreach ($owner_shares as $share_owner => $share_count) {
                        $fraction = $size * $share_count / $total_shares;
                        if (!isset($blame[$share_owner])) {
                            $blame[$share_owner] = ['exclusive' => 0, 'shared' => 0, 'nodes' => 0];
                        }
                        $blame[$share_owner]['shared'] += $fraction;
                    }
                }
            }
        }

        // Sort by total blamed
        $blame_sorted = [];
        foreach ($blame as $root => $b) {
            $total = $b['exclusive'] + $b['shared'];
            if ($total < 1024) {
                continue;
            }
            $blame_sorted[] = [
                'root' => $root,
                'name' => $root_link_names[$root] ?? "?",
                'exclusive' => $b['exclusive'],
                'shared' => $b['shared'],
                'total' => $total,
            ];
        }
        usort($blame_sorted, fn($a, $b) => $b['total'] <=> $a['total']);

        $total_heap = array_sum($this->substrate->node_sizes);
        $total_exclusive = 0;
        $total_shared = 0.0;

        $findings = [];
        foreach ($blame_sorted as $b) {
            $total_exclusive += $b['exclusive'];
            $total_shared += $b['shared'];
            $pct = $total_heap > 0 ? $b['total'] / $total_heap * 100.0 : 0;

            $findings[] = new Finding(
                kind: 'root_blame',
                severity: FindingSeverity::Info,
                confidence: $b['shared'] > $b['exclusive']
                    ? FindingConfidence::Low
                    : FindingConfidence::High,
                summary: sprintf(
                    '%s: %s (%.1f%%) — %s exclusive, %s shared',
                    $b['name'],
                    SizeFormatter::format($b['total']),
                    $pct,
                    SizeFormatter::format($b['exclusive']),
                    SizeFormatter::format($b['shared']),
                ),
                facts: [
                    'root_name' => $b['name'],
                    'exclusive_bytes' => (int)$b['exclusive'],
                    'shared_bytes' => (int)round($b['shared']),
                    'total_bytes' => (int)round($b['total']),
                    'percentage' => round($pct, 1),
                ],
                impact_bytes: (int)round($b['total']),
            );
        }

        return $findings;
    }
}
