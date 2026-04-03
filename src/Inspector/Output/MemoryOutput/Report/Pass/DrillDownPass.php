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
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument
     * @psalm-suppress MixedOperand, MixedArgumentTypeCoercion, InvalidOperand
     */
    #[\Override]
    public function analyze(): array
    {
        $link_stmt = $this->db->prepare(
            "SELECT link_name FROM context_edges"
            . " WHERE child_node_id = ? AND is_tree = 1"
            . " AND run_id = {$this->run_id} LIMIT 1"
        );
        $type_stmt = $this->db->prepare(
            "SELECT type FROM context_nodes"
            . " WHERE node_id = ? AND run_id = {$this->run_id} LIMIT 1"
        );
        $labeler = new NodeLabeler($this->db, $this->run_id);

        $path_parts = [];
        $path_types = [];
        $path_sizes = [];
        $current_children = $this->substrate->getRoots();
        $visited_canonicals = [];

        for ($depth = 0; $depth < 12; $depth++) {
            if (empty($current_children)) {
                break;
            }

            $branches = [];
            foreach ($current_children as $child_id) {
                $canon = $this->substrate->getCanonical($child_id);
                if (isset($visited_canonicals[$canon])) {
                    continue;
                }
                $size = $this->substrate->getSubtreeSize($child_id);
                $branches[] = [$child_id, $size, $canon];
            }
            if (empty($branches)) {
                break;
            }
            usort($branches, fn($a, $b) => $b[1] <=> $a[1]);

            $heaviest = $branches[0];
            $visited_canonicals[$heaviest[2]] = true;
            $link_stmt->execute([$heaviest[0]]);
            $r = $link_stmt->fetch(\PDO::FETCH_NUM);
            /** @var string $raw_name */
            $raw_name = $r ? $r[0] : '?';
            $name = $labeler->resolvePathLabel(
                $raw_name,
                $heaviest[0]
            );

            $type_stmt->execute([$heaviest[0]]);
            $type_row = $type_stmt->fetch(\PDO::FETCH_NUM);
            /** @var string $node_type */
            $node_type = $type_row ? $type_row[0] : '';

            $path_parts[] = $name;
            $path_types[] = $node_type;
            $path_sizes[] = $heaviest[1];

            $current_children = $this->substrate->getChildren($heaviest[0]);
        }

        if ($path_parts === []) {
            return [];
        }

        $path_str = PathFormatter::toPhpSyntax($path_parts, $path_types);
        $total_size = $path_sizes[0] ?? 0;

        return [
            new Finding(
                kind: 'bottleneck_path',
                severity: FindingSeverity::High,
                confidence: FindingConfidence::High,
                summary: sprintf(
                    '%s (%s)',
                    $path_str,
                    SizeFormatter::format($total_size),
                ),
                facts: [
                    'path' => $path_parts,
                    'sizes' => $path_sizes,
                    'depth' => count($path_parts),
                ],
                hypothesis: 'Heaviest memory path'
                    . ' — the primary chain of memory consumption',
                next_checks: [
                    'Examine the leaf for the actual data consuming memory',
                    'Check if the accumulation can be bounded or streamed',
                ],
                impact_bytes: $total_size,
            ),
        ];
    }
}
