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

final class DynamicPropertiesPass implements PassInterface
{
    private const DECLARED_PROPERTY_SLOT_SIZE = 16;

    public function __construct(
        private \PDO $db,
        private int $run_id,
        private GraphSubstrate $substrate,
    ) {
    }

    /**
     * @return list<Finding>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument, MixedOperand
     * @psalm-suppress PossiblyInvalidArgument, InvalidOperand
     */
    #[\Override]
    public function analyze(): array
    {
        $rows = $this->aggregateFromSubstrate();

        $findings = [];
        foreach ($rows as $row) {
            $short = $row['class_name'];
            $structural_bytes = $row['dynamic_properties_size'];
            $avoidable_bytes = $row['estimated_avoidable_bytes_if_static'];
            $cnt = $row['cnt'];
            $avg_properties = number_format($row['avg_properties_per_object'], 1);

            $findings[] = new Finding(
                kind: 'dynamic_properties_overhead',
                severity: $avoidable_bytes > 1024 * 1024
                    ? FindingSeverity::Medium
                    : FindingSeverity::Low,
                confidence: FindingConfidence::High,
                summary: sprintf(
                    '%s %s with dynamic properties: %s structural, ~%s avoidable'
                        . ' (%s/object, %s props/object)',
                    number_format($cnt),
                    $short,
                    SizeFormatter::format($structural_bytes),
                    SizeFormatter::format($avoidable_bytes),
                    SizeFormatter::format($row['avg_structural_bytes_per_object']),
                    $avg_properties,
                ),
                facts: [
                    'class_name' => $row['class_name'],
                    'count' => $cnt,
                    'dynamic_properties_size' => $structural_bytes,
                    'header_bytes' => $row['header_bytes'],
                    'used_table_bytes' => $row['used_table_bytes'],
                    'unused_table_bytes' => $row['unused_table_bytes'],
                    'property_count' => $row['property_count'],
                    'avg_properties_per_object' => $row['avg_properties_per_object'],
                    'avg_structural_bytes_per_object' => $row['avg_structural_bytes_per_object'],
                    'objects_with_unused_capacity' => $row['objects_with_unused_capacity'],
                    'estimated_avoidable_bytes_if_static' => $avoidable_bytes,
                ],
                hypothesis: 'This measures the HashTable structure behind dynamic properties'
                    . ' (header, used buckets, spare capacity), not the values stored in it.'
                    . ' Moving frequently used fields to declared properties removes most of'
                    . ' that structure cost.',
                next_checks: $this->buildNextChecks(
                    $row['class_name'],
                    $row['cnt'],
                    $row['objects_with_unused_capacity'],
                ),
                impact_bytes: $avoidable_bytes,
            );
        }

        return $findings;
    }

    /**
     * In-memory aggregation: walk every tree edge whose link_name is
     * 'dynamic_properties' from the substrate index, look the parent's
     * class and the child's (DP table's) size up directly. No SQL.
     *
     * @return list<array{
     *     class_name:string,
     *     cnt:int,
     *     dynamic_properties_size:int,
     *     header_bytes:int,
     *     used_table_bytes:int,
     *     unused_table_bytes:int,
     *     property_count:int,
     *     avg_properties_per_object:float,
     *     avg_structural_bytes_per_object:int,
     *     objects_with_unused_capacity:int,
     *     estimated_avoidable_bytes_if_static:int
     * }>
     */
    private function aggregateFromSubstrate(): array
    {
        /** @var array<string, array{
         *     cnt:int,
         *     header_bytes:int,
         *     used_table_bytes:int,
         *     unused_table_bytes:int,
         *     property_count:int,
         *     objects_with_unused_capacity:int
         * }> $stats
         */
        $stats = [];
        foreach ($this->substrate->iterateTreeChildrenByLinkName('dynamic_properties') as $dp_node_id) {
            $parent_id = $this->substrate->getTreeParentNodeId($dp_node_id);
            if ($parent_id === null) {
                continue;
            }
            $class_name = $this->substrate->getNodeClass($parent_id);
            if ($class_name === null) {
                continue;
            }

            if (!isset($stats[$class_name])) {
                $stats[$class_name] = [
                    'cnt' => 0,
                    'header_bytes' => 0,
                    'used_table_bytes' => 0,
                    'unused_table_bytes' => 0,
                    'property_count' => 0,
                    'objects_with_unused_capacity' => 0,
                ];
            }

            $header_bytes = $this->substrate->getNodeSize($dp_node_id);
            $used_table_bytes = 0;
            $unused_table_bytes = 0;
            $property_count = 0;

            foreach ($this->substrate->getChildren($dp_node_id) as $child_id) {
                $link_name = $this->substrate->getTreeLinkName($child_id);
                if ($link_name === 'array_elements') {
                    $used_table_bytes += $this->substrate->getNodeSize($child_id);
                    $property_count += count($this->substrate->getChildren($child_id));
                } elseif ($link_name === 'possible_unused_area') {
                    $unused_table_bytes += $this->substrate->getNodeSize($child_id);
                }
            }

            $stats[$class_name]['cnt']++;
            $stats[$class_name]['header_bytes'] += $header_bytes;
            $stats[$class_name]['used_table_bytes'] += $used_table_bytes;
            $stats[$class_name]['unused_table_bytes'] += $unused_table_bytes;
            $stats[$class_name]['property_count'] += $property_count;
            if ($unused_table_bytes > 0) {
                $stats[$class_name]['objects_with_unused_capacity']++;
            }
        }

        return $this->finalizeRows($stats);
    }

    /**
     * @param array<string, array{
     *     cnt:int,
     *     header_bytes:int,
     *     used_table_bytes:int,
     *     unused_table_bytes:int,
     *     property_count:int,
     *     objects_with_unused_capacity:int
     * }> $stats
     * @return list<array{
     *     class_name:string,
     *     cnt:int,
     *     dynamic_properties_size:int,
     *     header_bytes:int,
     *     used_table_bytes:int,
     *     unused_table_bytes:int,
     *     property_count:int,
     *     avg_properties_per_object:float,
     *     avg_structural_bytes_per_object:int,
     *     objects_with_unused_capacity:int,
     *     estimated_avoidable_bytes_if_static:int
     * }>
     */
    private function finalizeRows(array $stats): array
    {
        $rows = [];
        foreach ($stats as $class_name => $s) {
            if ($s['cnt'] > 10) {
                $structural_bytes = $s['header_bytes']
                    + $s['used_table_bytes']
                    + $s['unused_table_bytes'];
                $avoidable_bytes = max(
                    0,
                    $structural_bytes
                        - $s['property_count'] * self::DECLARED_PROPERTY_SLOT_SIZE,
                );
                $rows[] = [
                    'class_name' => $class_name,
                    'cnt' => $s['cnt'],
                    'dynamic_properties_size' => $structural_bytes,
                    'header_bytes' => $s['header_bytes'],
                    'used_table_bytes' => $s['used_table_bytes'],
                    'unused_table_bytes' => $s['unused_table_bytes'],
                    'property_count' => $s['property_count'],
                    'avg_properties_per_object' => (float)$s['property_count'] / (float)$s['cnt'],
                    'avg_structural_bytes_per_object' => (int)($structural_bytes / $s['cnt']),
                    'objects_with_unused_capacity' => $s['objects_with_unused_capacity'],
                    'estimated_avoidable_bytes_if_static' => $avoidable_bytes,
                ];
            }
        }
        usort(
            $rows,
            static fn(array $a, array $b): int
                => $b['estimated_avoidable_bytes_if_static'] <=> $a['estimated_avoidable_bytes_if_static']
        );
        return array_slice($rows, 0, 10);
    }

    /**
     * @return list<string>
     */
    private function buildNextChecks(
        string $class_name,
        int $count,
        int $objects_with_unused_capacity,
    ): array {
        $checks = [];
        if ($class_name === 'stdClass' || $class_name === \stdClass::class) {
            $checks[] = 'Consider replacing stdClass with a dedicated DTO/value object';
        } else {
            $checks[] = 'Declare frequently used dynamic properties explicitly in the class';
        }
        if ($objects_with_unused_capacity * 2 >= $count) {
            $checks[] = 'Many instances still have spare HashTable capacity; fixed fields may save more';
        }
        return $checks;
    }
}
