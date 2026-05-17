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

final class TopArraysPass implements PassInterface
{
    public function __construct(
        private GraphSubstrate $substrate,
    ) {
    }

    /**
     * @return list<Finding>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument, MixedOperand
     * @psalm-suppress InvalidOperand, PossiblyInvalidArgument, InvalidArgument
     */
    #[\Override]
    public function analyze(): array
    {
        $array_element_nodes = $this->loadArrayElementNodes();
        $labeler = new NodeLabeler($this->substrate);
        $use_retained = $this->substrate->hasSubtreeSizes();

        $arrays = [];
        foreach ($this->substrate->iterateNodeSizes() as $node => $shallow) {
            foreach ($this->substrate->getChildren($node) as $child) {
                if (isset($array_element_nodes[$child])) {
                    $retained = $use_retained
                        ? $this->substrate->getSubtreeSize($node)
                        : $shallow;
                    $elem_count = count(
                        $this->substrate->getChildren($child)
                    );
                    $arrays[] = [
                        'node_id' => $node,
                        'shallow' => $shallow,
                        'retained' => $retained ?: $shallow,
                        'element_count' => $elem_count,
                    ];
                    break;
                }
            }
        }

        usort($arrays, fn($a, $b) => $b['retained'] <=> $a['retained']);

        $findings = [];
        foreach (array_slice($arrays, 0, 10) as $arr) {
            $retained = $arr['retained'];
            $shallow = $arr['shallow'];
            $node_id = $arr['node_id'];

            if ($retained < 10240 && $shallow < 10240) {
                continue;
            }

            $elements = $arr['element_count'];
            $path = $this->buildFullPath($node_id, $labeler);

            if ($use_retained && $retained > $shallow) {
                $summary = sprintf(
                    '%s retained (table: %s), %s elements — %s',
                    SizeFormatter::format($retained),
                    SizeFormatter::format($shallow),
                    number_format($elements),
                    $path ?: '(root)',
                );
            } else {
                $summary = sprintf(
                    '%s array, %s elements — %s',
                    SizeFormatter::format($shallow),
                    number_format($elements),
                    $path ?: '(root)',
                );
            }

            $findings[] = new Finding(
                kind: 'large_array',
                severity: $retained > 1024 * 1024
                    ? FindingSeverity::Medium
                    : FindingSeverity::Low,
                confidence: FindingConfidence::High,
                summary: $summary,
                facts: [
                    'node_id' => $node_id,
                    'table_size' => $shallow,
                    'retained_size' => $retained,
                    'element_count' => $elements,
                    'owner_path' => $path,
                ],
                hypothesis: 'Large array allocation;'
                    . ' may be a cache, buffer, or accumulator',
                next_checks: [
                    'Check if array size is bounded',
                    'Consider SplFixedArray for known-size collections',
                ],
                impact_bytes: $retained,
                evidence_node_ids: [$node_id],
            );
        }

        return $findings;
    }

    /**
     * Set of every child node whose tree-edge link_name is 'array_elements'.
     *
     * Reads the substrate's in-memory tree-edge link index instead of
     * issuing a SELECT against context_edges — on huge dumps the SQL
     * fetch loop here used to dominate the pass.
     *
     * @return array<int, true> child_node_id => true
     */
    private function loadArrayElementNodes(): array
    {
        $set = [];
        foreach ($this->substrate->iterateTreeChildrenByLinkName('array_elements') as $child_id) {
            $set[$child_id] = true;
        }
        return $set;
    }

    /**
     * Walk from node to root via tree parent edges using the
     * substrate's in-memory tree-link / parent / type indexes.
     */
    private function buildFullPath(int $node_id, NodeLabeler $labeler): string
    {
        $parts = [];
        $types = [];
        $cur = $node_id;
        for ($i = 0; $i < 20; $i++) {
            $link = $this->substrate->getTreeLinkName($cur);
            if ($link === null) {
                break;
            }
            $parent = $this->substrate->getTreeParentNodeId($cur);
            if ($parent === null) {
                array_unshift($parts, $link);
                array_unshift($types, '');
                break;
            }
            array_unshift($parts, $labeler->resolvePathLabel($link, $cur));
            array_unshift($types, $this->substrate->getNodeType($cur) ?? '');
            $cur = $parent;
        }
        return PathFormatter::toPhpSyntax($parts, $types);
    }
}
