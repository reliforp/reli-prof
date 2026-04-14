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

final class DedupCandidatePass implements PassInterface
{
    private ?\PDOStatement $node_location_stmt = null;
    private ?\PDOStatement $tree_parent_stmt = null;

    /**
     * @param list<array{
     *     link_name: string,
     *     size: int,
     *     cnt: int,
     *     total_waste: int,
     *     sample_parent_node_id: int,
     *     sample_child_node_id: int,
     *     sample_location_type?: ?string,
     *     sample_child_node_ids?: list<int>,
     *     examples?: array<string, mixed>
     * }>|null $precomputed_dedup_candidates
     */
    public function __construct(
        private \PDO $db,
        private int $run_id,
        private ?GraphSubstrate $substrate = null,
        private ?array $precomputed_dedup_candidates = null,
    ) {
    }

    /**
     * @return list<Finding>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument, MixedOperand
     * @psalm-suppress PossiblyInvalidArgument, InvalidArgument, RiskyTruthyFalsyComparison
     * @psalm-suppress MixedArgumentTypeCoercion
     */
    #[\Override]
    public function analyze(): array
    {
        $rows = $this->precomputed_dedup_candidates
            ?? $this->loadDedupRowsFromSql();

        $findings = [];
        $use_retained = $this->substrate !== null
            && $this->substrate->hasSubtreeSizes();

        foreach ($rows as $row) {
            $link_name = $row['link_name'];
            if ($link_name === 'key') {
                continue;
            }

            $sample_child_node_id = $row['sample_child_node_id'];
            $sample_location_type = $row['sample_location_type']
                ?? $this->loadNodeLocationInfo($sample_child_node_id)['location_type'];
            if ($sample_location_type === 'ZendArrayMemoryLocation') {
                continue;
            }

            $cnt = $row['cnt'];
            $shallow_size = $row['size'];
            $total = $row['total_waste'];
            $size = $shallow_size;
            /** @var list<int>|null $sample_child_node_ids */
            $sample_child_node_ids = $row['sample_child_node_ids'] ?? null;

            if ($use_retained) {
                $retained = $this->getRetainedForDedup(
                    $link_name,
                    $shallow_size,
                    $sample_child_node_ids,
                );
                if ($retained > $shallow_size) {
                    $size = $retained;
                    $total = $cnt * $retained;
                }
            }

            $sample_parent_node_id = $row['sample_parent_node_id'];
            if ($this->substrate !== null) {
                [
                    'source_class' => $dedup_src,
                    'owner_prop' => $owner_prop,
                ] = $this->resolveDedupOwnerInfoFromSubstrate($sample_parent_node_id);
                $dedup_tgt = $this->substrate->getNodeClass($sample_child_node_id);
            } else {
                [
                    'source_class' => $dedup_src,
                    'owner_prop' => $owner_prop,
                ] = $this->resolveDedupOwnerInfoFromSql($sample_parent_node_id);
                $dedup_tgt = $this->loadNodeLocationInfo($sample_child_node_id)['class_name'];
            }

            $dedup_label = $this->buildDedupLabel(
                $dedup_src,
                $owner_prop,
                $link_name,
                $dedup_tgt,
            );

            /** @var array<string, mixed> $examples */
            $examples = $row['examples'] ?? $this->getDedupExamples($link_name, $shallow_size);

            [
                'hypothesis' => $hypothesis,
                'confidence' => $confidence,
            ] = $this->buildEvidenceSummary($examples, $cnt);

            $findings[] = new Finding(
                kind: 'dedup_candidate',
                severity: $total > 102400
                    ? FindingSeverity::Low
                    : FindingSeverity::Info,
                confidence: $confidence,
                summary: sprintf(
                    '%s: %s copies x %s%s = %s',
                    $dedup_label,
                    number_format($cnt),
                    SizeFormatter::format($size),
                    $size > $shallow_size ? ' retained' : '',
                    SizeFormatter::format($total),
                ),
                facts: [
                    'link_name' => $link_name,
                    'source_class' => $dedup_src,
                    'target_class' => $dedup_tgt,
                    'count' => $cnt,
                    'each_size' => $size,
                    'total_waste' => $total,
                    'examples' => $examples,
                ],
                hypothesis: $hypothesis,
                impact_bytes: $total,
            );
        }

        return $findings;
    }

    /**
     * @return list<array{
     *     link_name: string,
     *     size: int,
     *     cnt: int,
     *     total_waste: int,
     *     sample_parent_node_id: int,
     *     sample_child_node_id: int
     * }>
     * @psalm-suppress MixedAssignment
     */
    private function loadDedupRowsFromSql(): array
    {
        /** @var list<array{
         *     link_name: string,
         *     size: int,
         *     cnt: int,
         *     total_waste: int,
         *     sample_parent_node_id: int,
         *     sample_child_node_id: int
         * }> $rows
         */
        $rows = $this->db->query($this->childSizesCteSql() . "
            SELECT
                e.link_name,
                cs.node_size as size,
                count(DISTINCT e.child_node_id) as cnt,
                count(DISTINCT e.child_node_id) * cs.node_size as total_waste,
                min(e.parent_node_id) as sample_parent_node_id,
                min(e.child_node_id) as sample_child_node_id
            FROM context_edges e
            JOIN child_sizes cs
                ON cs.node_id = e.child_node_id
            WHERE e.run_id = {$this->run_id}
                AND e.is_tree = 0
                AND e.strength = 'strong'
                AND e.link_name <> 'key'
            GROUP BY e.link_name, cs.node_size
            HAVING count(DISTINCT e.child_node_id) > 50
                AND count(DISTINCT e.child_node_id) * cs.node_size > 10240
            ORDER BY total_waste DESC
            LIMIT 10
        ")->fetchAll(\PDO::FETCH_ASSOC);
        return $rows;
    }

    private function childSizesCteSql(): string
    {
        return "
            WITH child_sizes AS (
                SELECT
                    node_id,
                    sum(size) as node_size
                FROM context_node_locations
                WHERE run_id = {$this->run_id}
                GROUP BY node_id
            )
        ";
    }

    private function dedupChildrenCteSql(string $link_name, int $size): string
    {
        return "
            WITH child_sizes AS (
                SELECT
                    node_id,
                    sum(size) as node_size
                FROM context_node_locations
                WHERE run_id = {$this->run_id}
                GROUP BY node_id
            ),
            group_children AS (
                SELECT DISTINCT e.child_node_id
                FROM context_edges e
                JOIN child_sizes cs
                    ON cs.node_id = e.child_node_id
                WHERE e.run_id = {$this->run_id}
                    AND e.is_tree = 0
                    AND e.strength = 'strong'
                    AND e.link_name = " . $this->db->quote($link_name) . "
                    AND cs.node_size = {$size}
            )
        ";
    }

    /**
     * @return array{source_class: ?string, owner_prop: ?string}
     */
    private function resolveDedupOwnerInfoFromSubstrate(int $parent_node_id): array
    {
        assert($this->substrate !== null);

        $source_class = $this->resolveDirectSourceClassFromSubstrate($parent_node_id);
        if ($source_class !== null) {
            return [
                'source_class' => $source_class,
                'owner_prop' => null,
            ];
        }

        $array_elements_node_id = $this->substrate->getTreeParentNodeId($parent_node_id);
        if (
            $array_elements_node_id === null
            || $this->substrate->getTreeLinkName($array_elements_node_id) !== 'array_elements'
        ) {
            return [
                'source_class' => null,
                'owner_prop' => null,
            ];
        }

        $array_header_node_id = $this->substrate->getTreeParentNodeId($array_elements_node_id);
        if ($array_header_node_id === null) {
            return [
                'source_class' => null,
                'owner_prop' => null,
            ];
        }

        $owner_prop = $this->substrate->getTreeLinkName($array_header_node_id);
        $object_properties_node_id = $this->substrate->getTreeParentNodeId($array_header_node_id);
        if (
            $object_properties_node_id === null
            || $this->substrate->getTreeLinkName($object_properties_node_id) !== 'object_properties'
        ) {
            return [
                'source_class' => null,
                'owner_prop' => null,
            ];
        }

        $owner_node_id = $this->substrate->getTreeParentNodeId($object_properties_node_id);
        if ($owner_node_id === null) {
            return [
                'source_class' => null,
                'owner_prop' => null,
            ];
        }

        return [
            'source_class' => $this->substrate->getNodeClass($owner_node_id),
            'owner_prop' => $owner_prop,
        ];
    }

    /**
     * @return array{source_class: ?string, owner_prop: ?string}
     */
    private function resolveDedupOwnerInfoFromSql(int $parent_node_id): array
    {
        $source_class = $this->resolveDirectSourceClassFromSql($parent_node_id);
        if ($source_class !== null) {
            return [
                'source_class' => $source_class,
                'owner_prop' => null,
            ];
        }

        $array_element_info = $this->loadTreeParentInfo($parent_node_id);
        $array_elements_node_id = $array_element_info['parent_node_id'];
        if ($array_elements_node_id === null) {
            return [
                'source_class' => null,
                'owner_prop' => null,
            ];
        }

        $array_elements_info = $this->loadTreeParentInfo($array_elements_node_id);
        if ($array_elements_info['link_name'] !== 'array_elements') {
            return [
                'source_class' => null,
                'owner_prop' => null,
            ];
        }

        $array_header_node_id = $array_elements_info['parent_node_id'];
        if ($array_header_node_id === null) {
            return [
                'source_class' => null,
                'owner_prop' => null,
            ];
        }

        $array_header_info = $this->loadTreeParentInfo($array_header_node_id);
        $owner_prop = $array_header_info['link_name'];
        $object_properties_node_id = $array_header_info['parent_node_id'];
        if ($object_properties_node_id === null) {
            return [
                'source_class' => null,
                'owner_prop' => null,
            ];
        }

        $object_properties_info = $this->loadTreeParentInfo($object_properties_node_id);
        if ($object_properties_info['link_name'] !== 'object_properties') {
            return [
                'source_class' => null,
                'owner_prop' => null,
            ];
        }

        $owner_node_id = $object_properties_info['parent_node_id'];
        if ($owner_node_id === null) {
            return [
                'source_class' => null,
                'owner_prop' => null,
            ];
        }

        return [
            'source_class' => $this->loadNodeLocationInfo($owner_node_id)['class_name'],
            'owner_prop' => $owner_prop,
        ];
    }

    private function resolveDirectSourceClassFromSubstrate(int $parent_node_id): ?string
    {
        assert($this->substrate !== null);
        if ($this->substrate->getTreeLinkName($parent_node_id) !== 'object_properties') {
            return null;
        }

        $owner_node_id = $this->substrate->getTreeParentNodeId($parent_node_id);
        if ($owner_node_id === null) {
            return null;
        }

        return $this->substrate->getNodeClass($owner_node_id);
    }

    private function resolveDirectSourceClassFromSql(int $parent_node_id): ?string
    {
        $parent_info = $this->loadTreeParentInfo($parent_node_id);
        if ($parent_info['link_name'] !== 'object_properties') {
            return null;
        }

        $owner_node_id = $parent_info['parent_node_id'];
        if ($owner_node_id === null) {
            return null;
        }

        return $this->loadNodeLocationInfo($owner_node_id)['class_name'];
    }

    /**
     * @return array{parent_node_id: ?int, link_name: ?string}
     * @psalm-suppress MixedAssignment
     */
    private function loadTreeParentInfo(int $child_node_id): array
    {
        if ($this->tree_parent_stmt === null) {
            $this->tree_parent_stmt = $this->db->prepare(
                "SELECT parent_node_id, link_name FROM context_edges"
                . " WHERE run_id = ? AND child_node_id = ? AND is_tree = 1"
                . " LIMIT 1"
            );
        }

        $this->tree_parent_stmt->execute([$this->run_id, $child_node_id]);
        /** @var array{parent_node_id?: int|null, link_name?: string}|false $row */
        $row = $this->tree_parent_stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return [
                'parent_node_id' => null,
                'link_name' => null,
            ];
        }

        return [
            'parent_node_id' => array_key_exists('parent_node_id', $row)
                ? ($row['parent_node_id'] !== null ? $row['parent_node_id'] : null)
                : null,
            'link_name' => $row['link_name'] ?? null,
        ];
    }

    /**
     * @return array{location_type: ?string, class_name: ?string}
     * @psalm-suppress MixedAssignment
     */
    private function loadNodeLocationInfo(int $node_id): array
    {
        if ($this->node_location_stmt === null) {
            $this->node_location_stmt = $this->db->prepare(
                "SELECT location_type, class_name FROM context_node_locations"
                . " WHERE run_id = ? AND node_id = ? LIMIT 1"
            );
        }

        $this->node_location_stmt->execute([$this->run_id, $node_id]);
        /** @var array{location_type?: string, class_name?: string}|false $row */
        $row = $this->node_location_stmt->fetch(\PDO::FETCH_ASSOC);

        return [
            'location_type' => $row['location_type'] ?? null,
            'class_name' => $row['class_name'] ?? null,
        ];
    }

    /**
     * @param list<int>|null $sample_child_node_ids
     */
    private function getRetainedForDedup(
        string $link_name,
        int $shallow_size,
        ?array $sample_child_node_ids = null,
    ): int {
        assert($this->substrate !== null);

        if ($sample_child_node_ids === null) {
            if ($this->precomputed_dedup_candidates !== null) {
                return $shallow_size;
            }

            $stmt = $this->db->query($this->dedupChildrenCteSql($link_name, $shallow_size) . "
                SELECT child_node_id
                FROM group_children
                LIMIT 20
            ");

            $sample_child_node_ids = [];
            while (true) {
                /** @var int|string|false $nid */
                $nid = $stmt->fetchColumn();
                if ($nid === false) {
                    break;
                }
                $sample_child_node_ids[] = (int)$nid;
            }
        }

        $total = 0;
        $count = 0;
        foreach ($sample_child_node_ids as $node_id) {
            $retained = $this->substrate->getSubtreeSize($node_id);
            if ($retained > 0) {
                $total += $retained;
                $count++;
            }
        }

        return $count > 0 ? (int)($total / $count) : $shallow_size;
    }

    /**
     * @return array<string, mixed>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument
     */
    private function getDedupExamples(string $link_name, int $size): array
    {
        $str_rows = $this->db->query($this->dedupChildrenCteSql($link_name, $size) . "
            SELECT
                cnl.string_value,
                count(*) as cnt
            FROM group_children gc
            JOIN context_node_locations cnl
                ON cnl.node_id = gc.child_node_id
                AND cnl.run_id = {$this->run_id}
            WHERE cnl.string_value IS NOT NULL
            GROUP BY cnl.string_value
            ORDER BY count(*) DESC
            LIMIT 5
        ")->fetchAll(\PDO::FETCH_ASSOC);

        if ($str_rows !== []) {
            $top = $str_rows[0];
            $top_count = (int)$top['cnt'];
            $top_value = (string)($top['string_value'] ?? '');
            if (strlen($top_value) > 60) {
                $top_value = substr($top_value, 0, 57) . '...';
            }

            $samples = array_map(
                static function ($r): string {
                    $value = (string)($r['string_value'] ?? '');
                    return strlen($value) > 40
                        ? substr($value, 0, 37) . '...'
                        : $value;
                },
                $str_rows,
            );

            return [
                'type' => 'string',
                'identical_count' => $top_count > 1 ? $top_count : 0,
                'sample_value' => $top_value,
                'samples' => $samples,
                'distinct_values' => count($str_rows),
            ];
        }

        $obj_rows = $this->db->query($this->dedupChildrenCteSql($link_name, $size) . "
            SELECT
                gc.child_node_id,
                cnl.class_name,
                cnl.location_type
            FROM group_children gc
            JOIN context_node_locations cnl
                ON cnl.node_id = gc.child_node_id
                AND cnl.run_id = {$this->run_id}
            GROUP BY gc.child_node_id
            LIMIT 3
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $samples = [];
        foreach ($obj_rows as $row) {
            $label = $row['class_name']
                ?? $row['location_type']
                ?? 'unknown';
            $samples[] = "{$label} ({$size}B)";
        }

        return [
            'type' => 'object',
            'samples' => $samples,
            'identical_count' => 0,
        ];
    }

    private function buildDedupLabel(
        ?string $source_class,
        ?string $owner_prop,
        string $link_name,
        ?string $target_class,
    ): string {
        if ($source_class !== null && $owner_prop !== null) {
            $label = "{$source_class}::\${$owner_prop}[{$link_name}]";
        } elseif ($source_class !== null) {
            $label = "{$source_class}::\${$link_name}";
        } else {
            $label = $link_name;
        }

        if ($target_class !== null) {
            $label .= " ({$target_class})";
        }

        return $label;
    }

    /**
     * @param array<string, mixed> $examples
     * @return array{hypothesis: string, confidence: FindingConfidence}
     */
    private function buildEvidenceSummary(array $examples, int $count): array
    {
        $hypothesis = 'Multiple copies of same-size objects'
            . ' via shared references; may be shareable';
        $confidence = FindingConfidence::Low;

        if (($examples['type'] ?? null) === 'string') {
            $identical_count = (int)($examples['identical_count'] ?? 0);
            if ($identical_count > 0) {
                $pct = $count > 0
                    ? ((float)$identical_count / (float)$count) * 100.0
                    : 0.0;
                $hypothesis = sprintf(
                    '%d/%d copies have identical content (%.0f%%). Example: "%s"',
                    $identical_count,
                    $count,
                    $pct,
                    (string)($examples['sample_value'] ?? ''),
                );
                $confidence = $pct > 50.0
                    ? FindingConfidence::High
                    : FindingConfidence::Medium;
            } else {
                /** @var list<string> $samples */
                $samples = is_array($examples['samples'] ?? null)
                    ? $examples['samples']
                    : [];
                $hypothesis = sprintf(
                    'Same size but different content. Examples: "%s", "%s"',
                    $samples[0] ?? '?',
                    $samples[1] ?? '?',
                );
            }
        } elseif (($examples['type'] ?? null) === 'object') {
            /** @var mixed $raw_samples */
            $raw_samples = $examples['samples'] ?? null;
            /** @var list<string> $samples */
            $samples = is_array($raw_samples)
                ? array_slice($raw_samples, 0, 3)
                : [];
            if ($samples !== []) {
                $hypothesis .= sprintf('. Examples: %s', implode(', ', $samples));
            }
        }

        return [
            'hypothesis' => $hypothesis,
            'confidence' => $confidence,
        ];
    }
}
