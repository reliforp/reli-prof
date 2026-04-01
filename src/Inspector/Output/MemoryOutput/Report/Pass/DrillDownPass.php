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

final class DrillDownPass implements PassInterface
{
    public function __construct(
        private GraphSubstrate $substrate,
        private \PDO $db,
        private int $run_id,
    ) {
    }

    /**
     * @return list<Finding>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument, MixedOperand, MixedArgumentTypeCoercion, InvalidOperand
     */
    #[\Override]
    public function analyze(): array
    {
        $link_stmt = $this->db->prepare(
            "SELECT link_name FROM context_edges WHERE child_node_id = ? AND is_tree = 1 AND run_id = {$this->run_id} LIMIT 1"
        );

        $path_parts = [];
        $path_sizes = [];
        $current_children = $this->substrate->roots;

        for ($depth = 0; $depth < 12; $depth++) {
            if (empty($current_children)) {
                break;
            }

            $branches = [];
            foreach ($current_children as $child_id) {
                $size = $this->substrate->subtree_sizes[$child_id] ?? 0;
                $branches[] = [$child_id, $size];
            }
            usort($branches, fn($a, $b) => $b[1] <=> $a[1]);

            $heaviest = $branches[0];
            $link_stmt->execute([$heaviest[0]]);
            $r = $link_stmt->fetch(\PDO::FETCH_NUM);
            $name = $r ? $r[0] : '?';

            $path_parts[] = $name;
            $path_sizes[] = $heaviest[1];

            $current_children = $this->substrate->children[$heaviest[0]] ?? [];
        }

        if ($path_parts === []) {
            return [];
        }

        $path_str = implode(' -> ', $path_parts);
        $total_size = $path_sizes[0] ?? 0;

        return [
            new Finding(
                kind: 'bottleneck_path',
                severity: FindingSeverity::High,
                confidence: FindingConfidence::High,
                summary: sprintf(
                    '%s (%.2f MB)',
                    $path_str,
                    $total_size / 1024 / 1024,
                ),
                facts: [
                    'path' => $path_parts,
                    'sizes' => $path_sizes,
                    'depth' => count($path_parts),
                ],
                hypothesis: 'Heaviest memory path — the primary chain of memory consumption',
                next_checks: [
                    'Examine the leaf of this path for the actual data consuming memory',
                    'Check if the accumulation can be bounded or streamed',
                ],
                impact_bytes: $total_size,
            ),
        ];
    }
}
