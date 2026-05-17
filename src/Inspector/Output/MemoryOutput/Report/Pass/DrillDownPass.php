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
        // The link_name and node type lookups are answered straight
        // from the substrate's in-memory indexes (built once during
        // loadEdgesFfi / loadNodeTypesFfi). The previous version
        // issued one prepared-statement execute per depth level for
        // each, which on big dumps showed up as a small but real
        // hot spot in the trace.
        $labeler = new NodeLabeler($this->substrate);

        $path_parts = [];
        $path_types = [];
        $path_sizes = [];
        $path_node_ids = [];
        $path_branch_counts = [];
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
            $raw_name = $this->substrate->getTreeLinkName($heaviest[0]) ?? '?';
            $name = $labeler->resolvePathLabel(
                $raw_name,
                $heaviest[0]
            );

            $node_type = $this->substrate->getNodeType($heaviest[0]) ?? '';

            $path_parts[] = $name;
            $path_types[] = $node_type;
            $path_sizes[] = $heaviest[1];
            $path_node_ids[] = $heaviest[0];
            $path_branch_counts[] = count($branches);

            $current_children = $this->substrate->getChildren($heaviest[0]);
        }

        if ($path_parts === []) {
            return [];
        }

        [
            'summary_path' => $path_str,
            'leaf_path' => $leaf_path_str,
        ] = $this->selectSummaryPath(
            $path_parts,
            $path_types,
            $path_node_ids,
            $path_branch_counts,
        );
        $total_size = $path_sizes[0] ?? 0;
        $hypothesis = 'Heaviest retained branch'
            . ' — the primary chain of memory consumption';
        if ($leaf_path_str !== null) {
            $hypothesis .= "\nLeaf example: {$leaf_path_str}";
        }

        $next_checks = $leaf_path_str !== null
            ? [
                'Examine the leaf example for the actual data consuming memory',
                'Check if the accumulation can be bounded or streamed',
            ]
            : [
                'Examine the leaf for the actual data consuming memory',
                'Check if the accumulation can be bounded or streamed',
            ];

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
                    'path_types' => $path_types,
                    'sizes' => $path_sizes,
                    'depth' => count($path_parts),
                    'summary_path' => $path_str,
                    'leaf_path' => $leaf_path_str,
                ],
                hypothesis: $hypothesis,
                next_checks: $next_checks,
                impact_bytes: $total_size,
                evidence_node_ids: $path_node_ids,
                representative_paths: $leaf_path_str !== null
                    ? [$path_str, $leaf_path_str]
                    : [$path_str],
            ),
        ];
    }

    /**
     * Show the dominant owner path in the summary, while preserving the full
     * leaf path separately when the chosen terminal node is just one sibling
     * among several alternatives.
     *
     * @param list<string> $path_parts
     * @param list<string> $path_types
     * @param list<int> $path_node_ids
     * @param list<int> $path_branch_counts
     * @return array{summary_path: string, leaf_path: ?string}
     */
    private function selectSummaryPath(
        array $path_parts,
        array $path_types,
        array $path_node_ids,
        array $path_branch_counts,
    ): array {
        $full_path = PathFormatter::toPhpSyntax($path_parts, $path_types);
        if ($path_node_ids === []) {
            return [
                'summary_path' => $full_path,
                'leaf_path' => null,
            ];
        }

        $last_index = array_key_last($path_node_ids);
        $last_node_id = $path_node_ids[$last_index];
        $last_branch_count = $path_branch_counts[$last_index] ?? 1;
        if (
            $last_branch_count <= 1
            || $this->substrate->getChildren($last_node_id) !== []
        ) {
            return [
                'summary_path' => $full_path,
                'leaf_path' => null,
            ];
        }

        $summary_parts = $path_parts;
        $summary_types = $path_types;
        while (count($summary_parts) > 1) {
            array_pop($summary_parts);
            array_pop($summary_types);
            $candidate_path = PathFormatter::toPhpSyntax(
                $summary_parts,
                $summary_types,
            );
            if ($candidate_path !== $full_path) {
                return [
                    'summary_path' => $candidate_path,
                    'leaf_path' => $full_path,
                ];
            }
        }

        return [
            'summary_path' => $full_path,
            'leaf_path' => null,
        ];
    }
}
