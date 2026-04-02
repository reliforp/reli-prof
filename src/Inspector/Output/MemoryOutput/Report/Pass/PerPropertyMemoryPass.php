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

/**
 * Class-qualified per-property memory analysis using GraphSubstrate.
 *
 * Walks the tree edges in-memory: for each node with a class_name,
 * follows children -> object_properties -> property links -> value sizes.
 * O(edges), no SQL JOINs needed.
 */
final class PerPropertyMemoryPass implements PassInterface
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
     */
    #[\Override]
    public function analyze(): array
    {
        // Load link_names for edges we need
        $link_names = $this->loadLinkNames();

        // For each object node (has class_name), find object_properties child,
        // then aggregate property link → value size
        /**
         * @var array<string, array{
         *     count: int, total: int, class: string, prop: string
         * }> $stats keyed by "class::$prop"
         */
        $stats = [];

        foreach ($this->substrate->iterateNodeClasses() as $node_id => $class_name) {
            // Find the object_properties child
            foreach ($this->substrate->getChildren($node_id) as $child) {
                $link = $link_names[$child] ?? null;
                if ($link !== 'object_properties') {
                    continue;
                }
                // child is ObjectPropertiesContext — iterate its children
                foreach ($this->substrate->getChildren($child) as $prop_child) {
                    $prop_name = $link_names[$prop_child] ?? null;
                    if ($prop_name === null) {
                        continue;
                    }
                    $val_size = $this->substrate->getNodeSize($prop_child);
                    $key = $class_name . '::$' . $prop_name;
                    if (!isset($stats[$key])) {
                        $stats[$key] = [
                            'count' => 0,
                            'total' => 0,
                            'class' => $class_name,
                            'prop' => $prop_name,
                        ];
                    }
                    $stats[$key]['count']++;
                    $stats[$key]['total'] += $val_size;
                }
                break;
            }
        }

        // Sort by total descending, filter > 1MB for actionable findings
        uasort($stats, fn($a, $b) => $b['total'] <=> $a['total']);

        $findings = [];
        $i = 0;
        foreach ($stats as $s) {
            if ($s['total'] < 1024 * 1024 || $i >= 10) {
                break;
            }
            $short = $s['class'];
            $avg = $s['count'] > 0
                ? (int)($s['total'] / $s['count'])
                : 0;

            $findings[] = new Finding(
                kind: 'expensive_property',
                severity: FindingSeverity::Medium,
                confidence: FindingConfidence::Medium,
                summary: sprintf(
                    '%s::$%s: %s occurrences x %s = %s',
                    $short,
                    $s['prop'],
                    number_format($s['count']),
                    SizeFormatter::format($avg),
                    SizeFormatter::format($s['total']),
                ),
                facts: [
                    'class_name' => $s['class'],
                    'property_name' => $s['prop'],
                    'count' => $s['count'],
                    'total_size' => $s['total'],
                    'avg_size' => $avg,
                ],
                hypothesis: 'High-frequency property contributing'
                    . ' significant memory',
                next_checks: [
                    'Check if values can be shared or lazily loaded',
                    'Consider a more compact representation',
                ],
                impact_bytes: $s['total'],
            );
            $i++;
        }

        return $findings;
    }

    /**
     * Load link_name for each child_node_id (tree edges only).
     * @return array<int, string> child_node_id => link_name
     * @psalm-suppress MixedArrayAccess, MixedAssignment
     */
    private function loadLinkNames(): array
    {
        $rows = $this->db->query(
            "SELECT child_node_id, link_name FROM context_edges"
            . " WHERE is_tree = 1 AND run_id = {$this->run_id}"
        )->fetchAll(\PDO::FETCH_NUM);

        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r[0]] = (string)$r[1];
        }
        unset($rows);
        return $map;
    }
}
