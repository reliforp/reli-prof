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
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\LinkNameResolver;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\SizeFormatter;

final class StructuralDedupPass implements PassInterface
{
    public function __construct(
        private \PDO $db,
        private int $run_id,
        private ?GraphSubstrate $substrate = null,
        private ?LinkNameResolver $link_resolver = null,
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
        $shape_groups = $this->substrate !== null
            ? $this->analyzeWithGraph()
            : $this->analyzeWithSql();

        $findings = [];

        $dedup_candidates = array_filter(
            $shape_groups,
            fn($g) => $g['count'] >= 50
        );
        usort(
            $dedup_candidates,
            fn($a, $b) => $b['total_size'] <=> $a['total_size']
        );

        foreach (array_slice($dedup_candidates, 0, 10) as $g) {
            $short = $g['class'];
            $is_empty = $g['props'] === '';
            $waste = ($g['count'] - 1) * $g['size'];

            if ($is_empty) {
                $findings[] = new Finding(
                    kind: 'empty_object',
                    severity: $g['total_size'] > 102400
                        ? FindingSeverity::Medium
                        : FindingSeverity::Low,
                    confidence: FindingConfidence::High,
                    summary: sprintf(
                        '%s: %s instances x %s, no properties (%s)',
                        $short,
                        number_format($g['count']),
                        SizeFormatter::format($g['size']),
                        SizeFormatter::format($g['total_size']),
                    ),
                    facts: [
                        'class_name' => $g['class'],
                        'count' => $g['count'],
                        'each_size' => $g['size'],
                        'total_size' => $g['total_size'],
                    ],
                    hypothesis: 'Objects with no stored properties'
                        . ' — pure overhead, may be replaceable',
                    impact_bytes: $waste,
                    evidence_node_ids: [$g['example_id']],
                );
            } else {
                // Same class + same property names = same shape, but values may differ.
                // This is expected for any class with many instances — only actionable
                // if instances are actually interchangeable (flyweight pattern).
                $findings[] = new Finding(
                    kind: 'structural_duplicate',
                    severity: $waste > 102400
                        ? FindingSeverity::Low
                        : FindingSeverity::Info,
                    confidence: FindingConfidence::Low,
                    summary: sprintf(
                        '%s: %s identical shapes x %s = %s'
                        . ' (saving: %s)',
                        $short,
                        number_format($g['count']),
                        SizeFormatter::format($g['size']),
                        SizeFormatter::format($g['total_size']),
                        SizeFormatter::format($waste),
                    ),
                    facts: [
                        'class_name' => $g['class'],
                        'count' => $g['count'],
                        'each_size' => $g['size'],
                        'total_size' => $g['total_size'],
                        'theoretical_saving' => $waste,
                        'properties' => $g['props'],
                    ],
                    hypothesis: 'Same class and property names (shape match).'
                        . ' Values may differ — check if instances are interchangeable.',
                    impact_bytes: $waste,
                    evidence_node_ids: [$g['example_id']],
                );
            }
        }

        return $findings;
    }

    /**
     * In-memory analysis using GraphSubstrate. O(nodes).
     * @return list<array{class: string, size: int, props: string, count: int, total_size: int, example_id: int}>
     * @psalm-suppress MixedReturnTypeCoercion
     */
    private function analyzeWithGraph(): array
    {
        assert($this->substrate !== null);

        $resolver = $this->link_resolver ?? new LinkNameResolver($this->db, $this->run_id);

        /** @var array<string, array{class: string, size: int, props: string, count: int, total_size: int, example_id: int}> */
        $shape_groups = [];

        foreach ($this->substrate->iterateNodeClasses() as $node_id => $class_name) {
            // Skip non-canonical duplicates: same object from different
            // collection phases should not count as separate instances
            if (!$this->substrate->isCanonicalOrUnique($node_id)) {
                continue;
            }
            $size = $this->substrate->getNodeSize($node_id);

            // Find object_properties child, collect property names
            $props = [];
            $has_dynamic_props = false;
            foreach ($this->substrate->getChildren($node_id) as $child) {
                $child_link = $resolver->lookup($child);
                if ($child_link === 'object_properties') {
                    foreach ($this->substrate->getChildren($child) as $prop_child) {
                        $prop_name = $resolver->lookup($prop_child);
                        if ($prop_name !== null) {
                            $props[] = $prop_name;
                        }
                    }
                } elseif ($child_link === 'dynamic_properties') {
                    // stdClass and __set() classes store props here
                    $dyn_count = count($this->substrate->getChildren($child));
                    if ($dyn_count > 0) {
                        $has_dynamic_props = true;
                        $props[] = "[dynamic:{$dyn_count}]";
                    }
                }
            }

            sort($props);
            $prop_sig = implode(',', $props);
            $hash = $class_name . '|' . $size . '|' . $prop_sig;

            if (!isset($shape_groups[$hash])) {
                $shape_groups[$hash] = [
                    'class' => $class_name,
                    'size' => $size,
                    'props' => $prop_sig,
                    'count' => 0,
                    'total_size' => 0,
                    'example_id' => $node_id,
                ];
            }
            $shape_groups[$hash]['count']++;
            $shape_groups[$hash]['total_size'] += $size;
        }

        return array_values($shape_groups);
    }

    /**
     * SQL-based analysis (fallback when no substrate).
     * @return list<array{class: string, size: int, props: string, count: int, total_size: int, example_id: int}>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedReturnTypeCoercion
     * @psalm-suppress MixedArgument, MixedOperand
     */
    private function analyzeWithSql(): array
    {
        $rows = $this->db->query("
            SELECT
                cnl.node_id,
                cnl.class_name,
                cnl.size,
                group_concat(e_prop.link_name, '|') as property_names
            FROM context_node_locations cnl
            JOIN context_edges e_to_obj
                ON e_to_obj.parent_node_id = cnl.node_id
                AND e_to_obj.link_name = 'object_properties'
                AND e_to_obj.is_tree = 1
                AND e_to_obj.run_id = {$this->run_id}
            LEFT JOIN context_edges e_prop
                ON e_prop.parent_node_id = e_to_obj.child_node_id
                AND e_prop.is_tree = 1
                AND e_prop.run_id = {$this->run_id}
            WHERE cnl.location_type = 'ZendObjectMemoryLocation'
                AND cnl.class_name IS NOT NULL
                AND cnl.run_id = {$this->run_id}
            GROUP BY cnl.node_id
        ")->fetchAll(\PDO::FETCH_ASSOC);

        /** @var array<string, array{class: string, size: int, props: string, count: int, total_size: int, example_id: int}> */
        $shape_groups = [];
        foreach ($rows as $s) {
            $props = $s['property_names']
                ? explode('|', $s['property_names'])
                : [];
            sort($props);
            $prop_sig = implode(',', $props);
            $hash = $s['class_name'] . '|' . $s['size'] . '|' . $prop_sig;

            if (!isset($shape_groups[$hash])) {
                $shape_groups[$hash] = [
                    'class' => $s['class_name'],
                    'size' => (int)$s['size'],
                    'props' => $prop_sig,
                    'count' => 0,
                    'total_size' => 0,
                    'example_id' => (int)$s['node_id'],
                ];
            }
            $shape_groups[$hash]['count']++;
            $shape_groups[$hash]['total_size'] += (int)$s['size'];
        }
        unset($rows);

        return array_values($shape_groups);
    }
}
