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

    /**
     * @param list<array{
     *     link_name: string,
     *     size: int,
     *     cnt: int,
     *     total_waste: int,
     *     sample_parent_node_id: int,
     *     sample_child_node_id: int,
     *     sample_location_type?: ?string,
     *     target_class?: ?string,
     *     target_location_type?: ?string,
     *     sample_child_node_ids?: list<int>,
     *     examples?: array<string, mixed>
     * }>|null $precomputed_dedup_candidates
     */
    public function __construct(
        private \PDO $db,
        private int $run_id,
        private GraphSubstrate $substrate,
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

        foreach ($rows as $row) {
            $link_name = $row['link_name'];
            if ($link_name === 'key') {
                continue;
            }

            $sample_child_node_id = $row['sample_child_node_id'];
            $target_class = $row['target_class'] ?? null;
            $target_location_type = $row['target_location_type']
                ?? $row['sample_location_type']
                ?? null;
            if ($target_location_type === null) {
                $target_location_type = $this->loadNodeLocationInfo($sample_child_node_id)['location_type'];
            }
            if ($target_location_type === 'ZendArrayMemoryLocation') {
                continue;
            }

            $cnt = $row['cnt'];
            $shallow_size = $row['size'];
            $total = $row['total_waste'];
            $size = $shallow_size;
            /** @var list<int>|null $sample_child_node_ids */
            $sample_child_node_ids = $row['sample_child_node_ids'] ?? null;

            // Compute `total_waste` as the **union** of tree-edge subtrees
            // rooted at the bucket's member nodes. Each reachable node is
            // counted once even when N seeds share an SCC subtree, so this
            // value reads honestly as "memory currently sitting under any
            // of the N copies" and is intrinsically bounded by the heap
            // total — no `cnt × retained` over-count, no clamp needed.
            // See T2.1 in docs/internals/memory-report-implementation-handoff.md.
            $member_node_ids = $sample_child_node_ids
                ?? $this->loadDedupMemberNodeIds(
                    $link_name,
                    $shallow_size,
                    $target_class,
                    $target_location_type,
                );
            if ($member_node_ids !== []) {
                $union_size = $this->substrate->unionReachableTreeSize($member_node_ids);
                if ($union_size > 0) {
                    $total = $union_size;
                    $size = $cnt > 0 ? (int)($union_size / $cnt) : $shallow_size;
                }
            }

            $sample_parent_node_id = $row['sample_parent_node_id'];
            [
                'source_class' => $dedup_src,
                'owner_prop' => $owner_prop,
            ] = $this->resolveDedupOwnerInfoFromSubstrate($sample_parent_node_id);
            $dedup_tgt = $target_class ?? $this->substrate->getNodeClass($sample_child_node_id);

            $dedup_label = $this->buildDedupLabel(
                $dedup_src,
                $owner_prop,
                $link_name,
                $dedup_tgt,
            );

            /** @var array<string, mixed> $examples */
            $examples = $row['examples']
                ?? $this->getDedupExamples(
                    $link_name,
                    $shallow_size,
                    $target_class,
                    $target_location_type,
                );

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
                    $size > $shallow_size ? ' avg retained' : '',
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
                evidence_node_ids: $sample_child_node_ids !== null
                    ? array_slice($sample_child_node_ids, 0, 20)
                    : [$sample_child_node_id],
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
     *     sample_child_node_id: int,
     *     target_class: ?string,
     *     target_location_type: ?string
     * }>
     * @psalm-suppress MixedAssignment
     */
    private function loadDedupRowsFromSql(): array
    {
        // Bucket by (link_name, node_size, target identity). The target
        // identity (class_name when present, location_type otherwise) keeps
        // heterogeneous classes that happen to share a shallow size out of
        // the same group. Without it, e.g. Monolog\Handler\TestHandler (1
        // instance, retains the full handler tree) and 3000 LogRecord
        // instances (152 B each) collapse into one bucket whose
        // sample-mean retained size is wildly skewed by the outlier — and
        // the resulting × count blows the impact past the heap total.
        // See B6 in docs/internals/memory-report-ux-improvements.md.
        /** @var list<array{
         *     link_name: string,
         *     size: int,
         *     cnt: int,
         *     total_waste: int,
         *     sample_parent_node_id: int,
         *     sample_child_node_id: int,
         *     target_class: ?string,
         *     target_location_type: ?string
         * }> $rows
         */
        $rows = $this->db->query($this->childSizesCteSql() . "
            , child_classes AS (
                SELECT
                    node_id,
                    min(class_name) as class_name,
                    min(location_type) as location_type
                FROM context_node_locations
                WHERE run_id = {$this->run_id}
                GROUP BY node_id
            )
            SELECT
                e.link_name,
                cs.node_size as size,
                cc.class_name as target_class,
                cc.location_type as target_location_type,
                count(DISTINCT e.child_node_id) as cnt,
                count(DISTINCT e.child_node_id) * cs.node_size as total_waste,
                min(e.parent_node_id) as sample_parent_node_id,
                min(e.child_node_id) as sample_child_node_id
            FROM context_edges e
            JOIN child_sizes cs
                ON cs.node_id = e.child_node_id
            LEFT JOIN child_classes cc
                ON cc.node_id = e.child_node_id
            WHERE e.run_id = {$this->run_id}
                AND e.is_tree = 0
                AND e.strength = 'strong'
                AND e.link_name <> 'key'
            GROUP BY e.link_name, cs.node_size, cc.class_name, cc.location_type
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

    /**
     * @param ?string $target_class Class name of bucket members, when known.
     *     Filters group_children to the class-homogeneous subset so sample
     *     averaging in getRetainedForDedup / getDedupExamples sees only
     *     bucket members, not class-mates that happen to share a shallow
     *     size (B6).
     * @param ?string $target_location_type Location type fallback — applies
     *     when class_name is NULL (non-object children: arrays, strings).
     */
    private function dedupChildrenCteSql(
        string $link_name,
        int $size,
        ?string $target_class = null,
        ?string $target_location_type = null,
    ): string {
        $class_filter = '';
        if ($target_class !== null) {
            $class_filter .= ' AND cc.class_name = ' . $this->db->quote($target_class);
        } else {
            $class_filter .= ' AND cc.class_name IS NULL';
        }
        if ($target_location_type !== null) {
            $class_filter .= ' AND cc.location_type = ' . $this->db->quote($target_location_type);
        } else {
            $class_filter .= ' AND cc.location_type IS NULL';
        }
        return "
            WITH child_sizes AS (
                SELECT
                    node_id,
                    sum(size) as node_size
                FROM context_node_locations
                WHERE run_id = {$this->run_id}
                GROUP BY node_id
            ),
            child_classes AS (
                SELECT
                    node_id,
                    min(class_name) as class_name,
                    min(location_type) as location_type
                FROM context_node_locations
                WHERE run_id = {$this->run_id}
                GROUP BY node_id
            ),
            group_children AS (
                SELECT DISTINCT e.child_node_id
                FROM context_edges e
                JOIN child_sizes cs
                    ON cs.node_id = e.child_node_id
                LEFT JOIN child_classes cc
                    ON cc.node_id = e.child_node_id
                WHERE e.run_id = {$this->run_id}
                    AND e.is_tree = 0
                    AND e.strength = 'strong'
                    AND e.link_name = " . $this->db->quote($link_name) . "
                    AND cs.node_size = {$size}
                    {$class_filter}
            )
        ";
    }

    /**
     * @return array{source_class: ?string, owner_prop: ?string}
     */
    private function resolveDedupOwnerInfoFromSubstrate(int $parent_node_id): array
    {
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

    private function resolveDirectSourceClassFromSubstrate(int $parent_node_id): ?string
    {
        if ($this->substrate->getTreeLinkName($parent_node_id) !== 'object_properties') {
            return null;
        }

        $owner_node_id = $this->substrate->getTreeParentNodeId($parent_node_id);
        if ($owner_node_id === null) {
            return null;
        }

        return $this->substrate->getNodeClass($owner_node_id);
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
     * Load every child node_id in the dedup bucket (no LIMIT). Used as
     * seeds for `unionReachableTreeSize()` so the resulting impact value
     * reflects the full bucket, not a 20-sample subset.
     *
     * @return list<int>
     */
    private function loadDedupMemberNodeIds(
        string $link_name,
        int $shallow_size,
        ?string $target_class = null,
        ?string $target_location_type = null,
    ): array {
        if ($this->precomputed_dedup_candidates !== null) {
            return [];
        }

        $stmt = $this->db->query(
            $this->dedupChildrenCteSql(
                $link_name,
                $shallow_size,
                $target_class,
                $target_location_type,
            ) . "
            SELECT child_node_id
            FROM group_children
        "
        );

        $member_node_ids = [];
        while (true) {
            /** @var int|string|false $nid */
            $nid = $stmt->fetchColumn();
            if ($nid === false) {
                break;
            }
            $member_node_ids[] = (int)$nid;
        }
        return $member_node_ids;
    }

    /**
     * @return array<string, mixed>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument
     */
    private function getDedupExamples(
        string $link_name,
        int $size,
        ?string $target_class = null,
        ?string $target_location_type = null,
    ): array {
        $cte = $this->dedupChildrenCteSql(
            $link_name,
            $size,
            $target_class,
            $target_location_type,
        );
        $str_rows = $this->db->query($cte . "
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

        $obj_rows = $this->db->query($cte . "
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
