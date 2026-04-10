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
        // Assign each node to its root owner via BFS. The root's
        // link_name now comes from the substrate's tree-link index
        // (built once during loadEdges) instead of a per-root prepared
        // statement execute — that N+1 was 60% of total report time
        // on a 15 GB dump.
        $node_root_owner = [];
        $root_link_names = [];

        foreach ($this->substrate->getRoots() as $root) {
            $link_name = $this->substrate->getTreeLinkName($root);
            $root_link_names[$root] = $link_name ?? "root_{$root}";

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

        // Compute blame
        $blame = [];
        foreach ($this->substrate->getRoots() as $root) {
            $blame[$root] = ['exclusive' => 0, 'shared' => 0, 'nodes' => 0];
        }

        // Track which canonicals have been blamed to avoid double-counting
        $blamed_canonicals = [];

        foreach ($this->substrate->iterateNodeSizes() as $node => $size) {
            if ($size === 0) {
                continue;
            }

            // Skip non-canonical duplicates to avoid double-counting
            $canon = $this->substrate->getCanonical($node);
            if (isset($blamed_canonicals[$canon])) {
                continue;
            }
            $blamed_canonicals[$canon] = true;

            // Sum sizes across all nodes in the canonical group
            $group = $this->substrate->getCanonicalGroup($node);
            $group_size = 0;
            foreach ($group as $gnode) {
                $group_size += $this->substrate->getNodeSize($gnode);
            }

            $owner = $node_root_owner[$node] ?? null;
            if ($owner === null) {
                continue;
            }

            $in_count = $this->substrate->getIncomingCount($node) ?: 1;

            if ($in_count <= 1) {
                $blame[$owner]['exclusive'] += $group_size;
                $blame[$owner]['nodes']++;
            } else {
                $owner_shares = [];
                foreach ($this->substrate->getAllParents($node) as $parent) {
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
                    $blame[$owner]['exclusive'] += $group_size;
                } else {
                    foreach ($owner_shares as $share_owner => $share_count) {
                        $fraction = $group_size * $share_count / $total_shares;
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

        $total_heap = $this->substrate->getNodeSizesSum();
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
