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

/**
 * Detects 1:1 ownership patterns between classes.
 *
 * Finds cases where "every instance of class A owns an instance of class B"
 * via object_properties. This catches patterns like CommonMark's
 * DotAccessData (246K) being owned 1:1 by all Node subclasses (246K total),
 * which count-based companion detection misses because Node is split into
 * many subclasses.
 */
final class OwnershipPatternPass implements PassInterface
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
     * @psalm-suppress MixedOperand, InvalidOperand, PossiblyUndefinedArrayOffset
     */
    #[\Override]
    public function analyze(): array
    {
        $link_names = $this->loadLinkNames();

        // Build class instance counts (canonical-or-unique only)
        /** @var array<string, int> */
        $class_counts = [];
        foreach ($this->substrate->iterateNodeClasses() as $node_id => $cls) {
            if (!$this->substrate->isCanonicalOrUnique($node_id)) {
                continue;
            }
            $class_counts[$cls] = ($class_counts[$cls] ?? 0) + 1;
        }

        // For each object node, find owned classes via object_properties
        // Aggregate: owner_class → owned_class → count
        /** @var array<string, array<string, int>> */
        $ownership = [];

        foreach ($this->substrate->iterateNodeClasses() as $node_id => $owner_class) {
            // Skip non-canonical duplicates
            if (!$this->substrate->isCanonicalOrUnique($node_id)) {
                continue;
            }
            foreach ($this->substrate->getChildren($node_id) as $child) {
                if (($link_names[$child] ?? '') !== 'object_properties') {
                    continue;
                }
                // child = ObjectPropertiesContext, iterate its children
                foreach ($this->substrate->getChildren($child) as $prop_child) {
                    // prop_child may be the property value node
                    $owned_class = $this->substrate->getNodeClass($prop_child);
                    if ($owned_class !== null && $owned_class !== $owner_class) {
                        $ownership[$owner_class][$owned_class]
                            = ($ownership[$owner_class][$owned_class] ?? 0) + 1;
                    }
                    // Also check one level deeper (property → value → object)
                    foreach ($this->substrate->getChildren($prop_child) as $grandchild) {
                        $gc_class = $this->substrate->getNodeClass($grandchild);
                        if ($gc_class !== null && $gc_class !== $owner_class) {
                            $ownership[$owner_class][$gc_class]
                                = ($ownership[$owner_class][$gc_class] ?? 0) + 1;
                        }
                    }
                }
                break; // only one object_properties per object
            }
        }

        // Find ownership patterns: owned_count / owner_count >= 0.9
        // and owner_count > 100
        /** @var list<array{owner: string, owned: string, owner_count: int, owned_count: int, rate: float, prop: string}> */
        $patterns = [];

        foreach ($ownership as $owner_class => $owned_classes) {
            $owner_count = $class_counts[$owner_class] ?? 0;
            if ($owner_count < 100) {
                continue;
            }

            foreach ($owned_classes as $owned_class => $count) {
                $rate = $count / $owner_count;
                if ($rate < 0.9) {
                    continue;
                }

                // Find the property name that links them
                $prop = $this->findLinkingProperty(
                    $owner_class,
                    $owned_class,
                    $link_names,
                );

                $patterns[] = [
                    'owner' => $owner_class,
                    'owned' => $owned_class,
                    'owner_count' => $owner_count,
                    'owned_count' => $count,
                    'rate' => $rate,
                    'prop' => $prop,
                ];
            }
        }

        // Sort by owned_count descending
        usort($patterns, fn($a, $b) => $b['owned_count'] <=> $a['owned_count']);

        // Deduplicate: if A→B and C→B both exist, group by owned class
        /** @var array<string, list<array{owner: string, owned: string, owner_count: int, owned_count: int, rate: float, prop: string}>> */
        $by_owned = [];
        foreach ($patterns as $p) {
            $by_owned[$p['owned']][] = $p;
        }

        $findings = [];
        foreach (array_slice($by_owned, 0, 5, true) as $owned_class => $owners) {
            $total_owned = $class_counts[$owned_class] ?? 0;
            $total_owners = 0;
            $owner_list = [];
            foreach ($owners as $o) {
                $total_owners += $o['owned_count'];
                $owner_list[] = sprintf(
                    '%s (via $%s, %s instances)',
                    $o['owner'],
                    $o['prop'],
                    number_format($o['owned_count']),
                );
            }

            $coverage = $total_owned > 0
                ? $total_owners / $total_owned * 100.0
                : 0;

            $findings[] = new Finding(
                kind: 'ownership_pattern',
                severity: FindingSeverity::Medium,
                confidence: $coverage > 95.0
                    ? FindingConfidence::High
                    : FindingConfidence::Medium,
                summary: sprintf(
                    '%s (%s instances) owned 1:1 by %d class%s (%.0f%% coverage)',
                    $owned_class,
                    number_format($total_owned),
                    count($owners),
                    count($owners) > 1 ? 'es' : '',
                    $coverage,
                ),
                facts: [
                    'owned_class' => $owned_class,
                    'owned_count' => $total_owned,
                    'owners' => $owners,
                    'coverage_pct' => round($coverage, 1),
                ],
                hypothesis: count($owners) > 1
                    ? "Multiple parent classes each own a {$owned_class}.\n"
                    . 'Owners: ' . implode(', ', $owner_list)
                    : "Every {$owners[0]['owner']} owns a {$owned_class}.\n"
                    . ($owner_list[0] ?? ''),
                next_checks: [
                    "Reducing {$owned_class} count requires reducing owner count",
                    'Check if the owned object can be shared or lazily created',
                ],
                impact_bytes: ($class_counts[$owned_class] ?? 0)
                    * ($this->avgSize($owned_class)),
            );
        }

        return $findings;
    }

    /**
     * Find the property name that links owner_class to owned_class.
     * @psalm-suppress MixedArrayAccess
     * @param array<int, string> $link_names
     */
    private function avgSize(string $class_name): int
    {
        $total = 0;
        $count = 0;
        foreach ($this->substrate->iterateNodeClasses() as $nid => $cls) {
            if ($cls === $class_name && $this->substrate->isCanonicalOrUnique($nid)) {
                $total += $this->substrate->getNodeSize($nid);
                $count++;
                if ($count >= 10) {
                    break; // sample
                }
            }
        }
        return $count > 0 ? (int)($total / $count) : 0;
    }

    /**
     * @param array<int, string> $link_names
     * @psalm-suppress MixedAssignment, MixedReturnStatement
     */
    private function findLinkingProperty(
        string $owner_class,
        string $owned_class,
        array $link_names,
    ): string {
        // Sample: find one owner instance and check which property points to owned
        foreach ($this->substrate->iterateNodeClasses() as $node_id => $cls) {
            if ($cls !== $owner_class) {
                continue;
            }
            foreach ($this->substrate->getChildren($node_id) as $child) {
                if (($link_names[$child] ?? '') !== 'object_properties') {
                    continue;
                }
                foreach ($this->substrate->getChildren($child) as $prop_child) {
                    $prop_name = $link_names[$prop_child] ?? '';
                    // Direct child
                    if ($this->substrate->getNodeClass($prop_child) === $owned_class) {
                        return $prop_name;
                    }
                    // One level deeper
                    foreach ($this->substrate->getChildren($prop_child) as $gc) {
                        if ($this->substrate->getNodeClass($gc) === $owned_class) {
                            return $prop_name;
                        }
                    }
                }
                break;
            }
            // Found one sample, return
            return '?';
        }
        return '?';
    }

    /**
     * @return array<int, string>
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
