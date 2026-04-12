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

final class NonTreeEdgePass implements PassInterface
{
    private ?\PDOStatement $node_location_stmt = null;

    public function __construct(
        private \PDO $db,
        private int $run_id,
        private ?GraphSubstrate $substrate = null,
    ) {
    }

    /**
     * @return list<Finding>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument, MixedOperand, InvalidOperand
     * @psalm-suppress PossiblyInvalidArgument, InvalidArgument, RiskyTruthyFalsyComparison
     * @psalm-suppress MixedArgumentTypeCoercion
     */
    #[\Override]
    public function analyze(): array
    {
        if ($this->substrate !== null) {
            return $this->analyzeWithSubstrate();
        }

        return $this->analyzeWithSql();
    }

    /**
     * SQL-only path for report generation without GraphSubstrate.
     *
     * @return list<Finding>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument, MixedOperand, InvalidOperand
     * @psalm-suppress PossiblyInvalidArgument, InvalidArgument, RiskyTruthyFalsyComparison
     * @psalm-suppress MixedArgumentTypeCoercion
     */
    private function analyzeWithSql(): array
    {
        // Classify shared references by link_name with class context
        $stmt = $this->db->query("
            SELECT
                e.link_name,
                count(*) as ref_count,
                count(DISTINCT e.child_node_id) as target_count,
                round(
                    count(*) * 1.0
                    / max(1, count(DISTINCT e.child_node_id)),
                    1
                ) as avg_refs,
                (SELECT cnl_src.class_name
                    FROM context_edges e_pp
                    JOIN context_node_locations cnl_src
                        ON cnl_src.node_id = e_pp.parent_node_id
                        AND cnl_src.run_id = {$this->run_id}
                        AND cnl_src.location_type = 'ZendObjectMemoryLocation'
                    WHERE e_pp.child_node_id = e.parent_node_id
                        AND e_pp.link_name = 'object_properties'
                        AND e_pp.run_id = {$this->run_id}
                    LIMIT 1
                ) as source_class,
                (SELECT cnl_tgt.class_name
                    FROM context_node_locations cnl_tgt
                    WHERE cnl_tgt.node_id = e.child_node_id
                        AND cnl_tgt.run_id = {$this->run_id}
                        AND cnl_tgt.class_name IS NOT NULL
                    LIMIT 1
                ) as target_class
            FROM context_edges e
            WHERE e.run_id = {$this->run_id}
                AND e.is_tree = 0
                AND e.strength = 'strong'
            GROUP BY e.link_name
            HAVING count(*) > 10
            ORDER BY count(*) DESC
            LIMIT 20
        ");

        $findings = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            // Skip numeric indices (array element sharing, not meaningful)
            if (ctype_digit($row['link_name'])) {
                continue;
            }
            $ref_count = (int)$row['ref_count'];
            $target_count = (int)$row['target_count'];
            $avg_refs = (float)$row['avg_refs'];
            $src = $row['source_class'] ?? null;
            $tgt = $row['target_class'] ?? null;
            $qualified = $src
                ? "{$src}::\${$row['link_name']}"
                : $row['link_name'];
            $target_label = $tgt ? " ({$tgt})" : '';

            if ($target_count === 1 && $ref_count > 50) {
                $findings[] = new Finding(
                    kind: 'shared_singleton',
                    severity: FindingSeverity::Info,
                    confidence: FindingConfidence::High,
                    summary: sprintf(
                        '%s%s: %s refs -> 1 target [singleton]',
                        $qualified,
                        $target_label,
                        number_format($ref_count),
                    ),
                    facts: [
                        'link_name' => $row['link_name'],
                        'source_class' => $src,
                        'target_class' => $tgt,
                        'ref_count' => $ref_count,
                        'target_count' => $target_count,
                    ],
                );
            } elseif ($target_count > 1 && $avg_refs > 2.0) {
                $findings[] = new Finding(
                    kind: 'shared_fanin',
                    severity: FindingSeverity::Info,
                    confidence: FindingConfidence::Medium,
                    summary: sprintf(
                        '%s -> %s (%s refs -> %s targets, %.1f each)',
                        $qualified,
                        $tgt ?? '?',
                        number_format($ref_count),
                        number_format($target_count),
                        $avg_refs,
                    ),
                    facts: [
                        'link_name' => $row['link_name'],
                        'ref_count' => $ref_count,
                        'target_count' => $target_count,
                        'avg_refs_per_target' => $avg_refs,
                    ],
                    hypothesis: 'Multiple references to shared objects — may indicate cycle back-references',
                    next_checks: [
                        'Check if these are intentional shared references or cycle artifacts',
                    ],
                );
            }
        }

        // Dedup candidates: same link_name, same size, many copies
        $dedup_rows = $this->db->query("
            SELECT
                e.link_name,
                cnl.size,
                cnl.class_name as target_class,
                cnl.location_type,
                count(*) as cnt,
                count(*) * cnl.size as total_waste,
                COALESCE(
                    (SELECT cnl_src.class_name
                        FROM context_edges e_pp
                        JOIN context_node_locations cnl_src
                            ON cnl_src.node_id = e_pp.parent_node_id
                            AND cnl_src.run_id = {$this->run_id}
                            AND cnl_src.location_type
                                = 'ZendObjectMemoryLocation'
                        WHERE e_pp.child_node_id = e.parent_node_id
                            AND e_pp.link_name = 'object_properties'
                            AND e_pp.run_id = {$this->run_id}
                        LIMIT 1
                    ),
                    (SELECT cnl_arr.class_name
                        FROM context_edges e_elem
                        JOIN context_edges e_elems
                            ON e_elems.child_node_id
                                = e_elem.parent_node_id
                            AND e_elems.link_name = 'array_elements'
                            AND e_elems.run_id = {$this->run_id}
                        JOIN context_edges e_prop
                            ON e_prop.child_node_id
                                = e_elems.parent_node_id
                            AND e_prop.run_id = {$this->run_id}
                        JOIN context_edges e_op
                            ON e_op.child_node_id
                                = e_prop.parent_node_id
                            AND e_op.link_name = 'object_properties'
                            AND e_op.run_id = {$this->run_id}
                        JOIN context_node_locations cnl_arr
                            ON cnl_arr.node_id = e_op.parent_node_id
                            AND cnl_arr.run_id = {$this->run_id}
                            AND cnl_arr.location_type
                                = 'ZendObjectMemoryLocation'
                        WHERE e_elem.child_node_id
                            = e.parent_node_id
                            AND e_elem.run_id = {$this->run_id}
                        LIMIT 1
                    )
                ) as source_class,
                (SELECT e_prop2.link_name
                    FROM context_edges e_elem2
                    JOIN context_edges e_elems2
                        ON e_elems2.child_node_id
                            = e_elem2.parent_node_id
                        AND e_elems2.link_name = 'array_elements'
                        AND e_elems2.run_id = {$this->run_id}
                    JOIN context_edges e_prop2
                        ON e_prop2.child_node_id
                            = e_elems2.parent_node_id
                        AND e_prop2.run_id = {$this->run_id}
                    WHERE e_elem2.child_node_id = e.parent_node_id
                        AND e_elem2.run_id = {$this->run_id}
                    LIMIT 1
                ) as owner_prop
            FROM context_edges e
            JOIN context_node_locations cnl
                ON cnl.node_id = e.child_node_id
                AND cnl.run_id = {$this->run_id}
            WHERE e.run_id = {$this->run_id}
                AND e.is_tree = 0
                AND e.strength = 'strong'
            GROUP BY e.link_name, cnl.size
            HAVING count(*) > 50 AND count(*) * cnl.size > 10240
            ORDER BY count(*) * cnl.size DESC
            LIMIT 10
        ");

        $use_retained = $this->substrate !== null
            && $this->substrate->hasSubtreeSizes();

        foreach ($dedup_rows as $row) {
            $cnt = (int)$row['cnt'];
            $shallow_size = (int)$row['size'];
            $total = (int)$row['total_waste'];

            // Skip array headers (always 56B) — "SAME SIZE" is meaningless
            if (($row['location_type'] ?? '') === 'ZendArrayMemoryLocation') {
                continue;
            }
            // Skip ArrayElementContext key links — the value side is
            // the actionable finding, key side is just noise
            if ($row['link_name'] === 'key') {
                continue;
            }

            // Use retained size when substrate available
            $size = $shallow_size;
            if ($use_retained) {
                $retained = $this->getRetainedForDedup(
                    (string)$row['link_name'],
                    $shallow_size,
                );
                if ($retained > $shallow_size) {
                    $size = $retained;
                    $total = $cnt * $retained;
                }
            }

            $dedup_src = $row['source_class'] ?? null;
            $dedup_tgt = $row['target_class'] ?? null;
            $owner_prop = $row['owner_prop'] ?? null;

            // Build label: Class::$prop[link] or Class::$prop or link_name
            if ($dedup_src && $owner_prop) {
                // Array element path: Class::$prop[link_name]
                $dedup_label = "{$dedup_src}::\${$owner_prop}"
                    . "[{$row['link_name']}]";
            } elseif ($dedup_src) {
                $dedup_label = "{$dedup_src}::\${$row['link_name']}";
            } else {
                $dedup_label = $row['link_name'];
            }
            if ($dedup_tgt) {
                $dedup_label .= " ({$dedup_tgt})";
            }

            // Check actual duplication for representative examples
            $examples = $this->getDedupExamples(
                $row['link_name'],
                $shallow_size,
            );

            $hypothesis = 'Multiple copies of same-size objects'
                . ' via shared references; may be shareable';
            $confidence = FindingConfidence::Low;

            if ($examples['type'] === 'string') {
                if ($examples['identical_count'] > 0) {
                    $pct = $cnt > 0
                        ? $examples['identical_count'] / $cnt * 100.0
                        : 0;
                    $hypothesis = sprintf(
                        '%d/%d copies have identical content (%.0f%%).'
                        . ' Example: "%s"',
                        $examples['identical_count'],
                        $cnt,
                        $pct,
                        $examples['sample_value'],
                    );
                    $confidence = $pct > 50.0
                        ? FindingConfidence::High
                        : FindingConfidence::Medium;
                } else {
                    $hypothesis = sprintf(
                        'Same size but different content.'
                        . ' Examples: "%s", "%s"',
                        $examples['samples'][0] ?? '?',
                        $examples['samples'][1] ?? '?',
                    );
                }
            } elseif ($examples['type'] === 'object') {
                $hypothesis .= sprintf(
                    '. Examples: %s',
                    implode(', ', array_slice($examples['samples'], 0, 3)),
                );
            }

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
                    'link_name' => $row['link_name'],
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
     * Full-analysis path: keep the expensive aggregation in SQL, but resolve
     * class/owner metadata only for top candidates.
     *
     * @return list<Finding>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument, MixedOperand, InvalidOperand
     * @psalm-suppress PossiblyInvalidArgument, InvalidArgument, RiskyTruthyFalsyComparison
     * @psalm-suppress MixedArgumentTypeCoercion
     */
    private function analyzeWithSubstrate(): array
    {
        assert($this->substrate !== null);

        $findings = [];
        $shared_rows = $this->db->query("
            SELECT
                e.link_name,
                count(*) as ref_count,
                count(DISTINCT e.child_node_id) as target_count,
                min(e.parent_node_id) as sample_parent_node_id,
                min(e.child_node_id) as sample_child_node_id
            FROM context_edges e
            WHERE e.run_id = {$this->run_id}
                AND e.is_tree = 0
                AND e.strength = 'strong'
            GROUP BY e.link_name
            HAVING count(*) > 10
            ORDER BY count(*) DESC
            LIMIT 20
        ")->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($shared_rows as $row) {
            $link_name = (string)$row['link_name'];
            if (ctype_digit($link_name)) {
                continue;
            }

            $ref_count = (int)$row['ref_count'];
            $target_count = (int)$row['target_count'];
            $avg_refs = round($ref_count / max(1, $target_count), 1);
            $source_class = $this->resolveDirectSourceClass(
                (int)$row['sample_parent_node_id'],
            );
            $target_class = $this->substrate->getNodeClass(
                (int)$row['sample_child_node_id']
            );
            $qualified = $source_class
                ? "{$source_class}::\${$link_name}"
                : $link_name;
            $target_label = $target_class ? " ({$target_class})" : '';

            if ($target_count === 1 && $ref_count > 50) {
                $findings[] = new Finding(
                    kind: 'shared_singleton',
                    severity: FindingSeverity::Info,
                    confidence: FindingConfidence::High,
                    summary: sprintf(
                        '%s%s: %s refs -> 1 target [singleton]',
                        $qualified,
                        $target_label,
                        number_format($ref_count),
                    ),
                    facts: [
                        'link_name' => $link_name,
                        'source_class' => $source_class,
                        'target_class' => $target_class,
                        'ref_count' => $ref_count,
                        'target_count' => $target_count,
                    ],
                );
            } elseif ($target_count > 1 && $avg_refs > 2.0) {
                $findings[] = new Finding(
                    kind: 'shared_fanin',
                    severity: FindingSeverity::Info,
                    confidence: FindingConfidence::Medium,
                    summary: sprintf(
                        '%s -> %s (%s refs -> %s targets, %.1f each)',
                        $qualified,
                        $target_class ?? '?',
                        number_format($ref_count),
                        number_format($target_count),
                        $avg_refs,
                    ),
                    facts: [
                        'link_name' => $link_name,
                        'ref_count' => $ref_count,
                        'target_count' => $target_count,
                        'avg_refs_per_target' => $avg_refs,
                    ],
                    hypothesis: 'Multiple references to shared objects — may indicate cycle back-references',
                    next_checks: [
                        'Check if these are intentional shared references or cycle artifacts',
                    ],
                );
            }
        }

        $dedup_rows = $this->db->query("
            SELECT
                e.link_name,
                cnl.size,
                count(*) as cnt,
                count(*) * cnl.size as total_waste,
                min(e.parent_node_id) as sample_parent_node_id,
                min(e.child_node_id) as sample_child_node_id
            FROM context_edges e
            JOIN context_node_locations cnl
                ON cnl.node_id = e.child_node_id
                AND cnl.run_id = {$this->run_id}
            WHERE e.run_id = {$this->run_id}
                AND e.is_tree = 0
                AND e.strength = 'strong'
            GROUP BY e.link_name, cnl.size
            HAVING count(*) > 50 AND count(*) * cnl.size > 10240
            ORDER BY count(*) * cnl.size DESC
            LIMIT 10
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $use_retained = $this->substrate->hasSubtreeSizes();
        foreach ($dedup_rows as $row) {
            $link_name = (string)$row['link_name'];
            $cnt = (int)$row['cnt'];
            $shallow_size = (int)$row['size'];
            $total = (int)$row['total_waste'];
            $sample_parent_node_id = (int)$row['sample_parent_node_id'];
            $sample_child_node_id = (int)$row['sample_child_node_id'];

            $target_info = $this->loadNodeLocationInfo($sample_child_node_id);
            if (($target_info['location_type'] ?? null) === 'ZendArrayMemoryLocation') {
                continue;
            }

            $size = $shallow_size;
            if ($use_retained) {
                $retained = $this->getRetainedForDedup(
                    $link_name,
                    $shallow_size,
                );
                if ($retained > $shallow_size) {
                    $size = $retained;
                    $total = $cnt * $retained;
                }
            }

            [
                'source_class' => $dedup_src,
                'owner_prop' => $owner_prop,
            ] = $this->resolveDedupOwnerInfo(
                $sample_parent_node_id,
            );
            $dedup_tgt = $this->substrate->getNodeClass($sample_child_node_id);

            if ($dedup_src && $owner_prop) {
                $dedup_label = "{$dedup_src}::\${$owner_prop}[{$link_name}]";
            } elseif ($dedup_src) {
                $dedup_label = "{$dedup_src}::\${$link_name}";
            } else {
                $dedup_label = $link_name;
            }
            if ($dedup_tgt) {
                $dedup_label .= " ({$dedup_tgt})";
            }

            $examples = $this->getDedupExamples($link_name, $shallow_size);

            $hypothesis = 'Multiple copies of same-size objects'
                . ' via shared references; may be shareable';
            $confidence = FindingConfidence::Low;

            if ($examples['type'] === 'string') {
                if ($examples['identical_count'] > 0) {
                    $pct = $cnt > 0
                        ? $examples['identical_count'] / $cnt * 100.0
                        : 0;
                    $hypothesis = sprintf(
                        '%d/%d copies have identical content (%.0f%%).'
                        . ' Example: "%s"',
                        $examples['identical_count'],
                        $cnt,
                        $pct,
                        $examples['sample_value'],
                    );
                    $confidence = $pct > 50.0
                        ? FindingConfidence::High
                        : FindingConfidence::Medium;
                } else {
                    $hypothesis = sprintf(
                        'Same size but different content.'
                        . ' Examples: "%s", "%s"',
                        $examples['samples'][0] ?? '?',
                        $examples['samples'][1] ?? '?',
                    );
                }
            } elseif ($examples['type'] === 'object') {
                $hypothesis .= sprintf(
                    '. Examples: %s',
                    implode(', ', array_slice($examples['samples'], 0, 3)),
                );
            }

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

    private function resolveDirectSourceClass(int $parent_node_id): ?string
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

    /**
     * @return array{source_class: ?string, owner_prop: ?string}
     */
    private function resolveDedupOwnerInfo(int $parent_node_id): array
    {
        assert($this->substrate !== null);

        $source_class = $this->resolveDirectSourceClass($parent_node_id);
        if ($source_class !== null) {
            return [
                'source_class' => $source_class,
                'owner_prop' => null,
            ];
        }

        $array_elements_node_id = $this->substrate->getTreeParentNodeId($parent_node_id);
        if ($array_elements_node_id === null) {
            return [
                'source_class' => null,
                'owner_prop' => null,
            ];
        }

        if ($this->substrate->getTreeLinkName($array_elements_node_id) !== 'array_elements') {
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
     * @return array{location_type: ?string}
     * @psalm-suppress MixedAssignment
     */
    private function loadNodeLocationInfo(int $node_id): array
    {
        if ($this->node_location_stmt === null) {
            $this->node_location_stmt = $this->db->prepare(
                "SELECT location_type FROM context_node_locations"
                . " WHERE run_id = ? AND node_id = ? LIMIT 1"
            );
        }

        $this->node_location_stmt->execute([$this->run_id, $node_id]);
        /** @var array{location_type?: string}|false $row */
        $row = $this->node_location_stmt->fetch(\PDO::FETCH_ASSOC);

        return [
            'location_type' => $row['location_type'] ?? null,
        ];
    }

    /**
     * Get average retained size for a dedup candidate group.
     * @psalm-suppress MixedArrayAccess, MixedAssignment
     */
    private function getRetainedForDedup(
        string $link_name,
        int $shallow_size,
    ): int {
        assert($this->substrate !== null);

        // Sample up to 20 child_node_ids from this group
        $stmt = $this->db->query("
            SELECT e.child_node_id
            FROM context_edges e
            JOIN context_node_locations cnl
                ON cnl.node_id = e.child_node_id
                AND cnl.run_id = {$this->run_id}
                AND cnl.size = {$shallow_size}
            WHERE e.run_id = {$this->run_id}
                AND e.is_tree = 0
                AND e.strength = 'strong'
                AND e.link_name = " . $this->db->quote($link_name) . "
            LIMIT 20
        ");

        $total = 0;
        $count = 0;
        while (($nid = $stmt->fetchColumn()) !== false) {
            $retained = $this->substrate->getSubtreeSize((int)$nid);
            if ($retained > 0) {
                $total += $retained;
                $count++;
            }
        }

        return $count > 0 ? (int)($total / $count) : $shallow_size;
    }

    /**
     * Get representative examples for a dedup candidate group.
     * For strings: check if values are actually identical.
     * For objects: show class + property count.
     *
     * @return array<string, mixed>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument
     */
    private function getDedupExamples(
        string $link_name,
        int $size,
    ): array {
        // Check if targets are strings with string_value
        $str_rows = $this->db->query("
            SELECT
                cnl.string_value,
                count(*) as cnt
            FROM context_edges e
            JOIN context_node_locations cnl
                ON cnl.node_id = e.child_node_id
                AND cnl.run_id = {$this->run_id}
            WHERE e.run_id = {$this->run_id}
                AND e.is_tree = 0
                AND e.strength = 'strong'
                AND e.link_name = " . $this->db->quote($link_name) . "
                AND cnl.size = {$size}
                AND cnl.string_value IS NOT NULL
            GROUP BY cnl.string_value
            ORDER BY count(*) DESC
            LIMIT 5
        ")->fetchAll(\PDO::FETCH_ASSOC);

        if ($str_rows !== []) {
            $top = $str_rows[0];
            $top_count = (int)$top['cnt'];
            $top_value = $top['string_value'] ?? '';
            if (strlen($top_value) > 60) {
                $top_value = substr($top_value, 0, 57) . '...';
            }
            $samples = array_map(
                function ($r) {
                    $v = $r['string_value'] ?? '';
                    return strlen($v) > 40
                        ? substr($v, 0, 37) . '...'
                        : $v;
                },
                $str_rows
            );
            return [
                'type' => 'string',
                'identical_count' => $top_count > 1 ? $top_count : 0,
                'sample_value' => $top_value,
                'samples' => $samples,
                'distinct_values' => count($str_rows),
            ];
        }

        // For non-string targets, show class/size samples
        $obj_rows = $this->db->query("
            SELECT
                cnl.class_name,
                cnl.location_type,
                cnl.size
            FROM context_edges e
            JOIN context_node_locations cnl
                ON cnl.node_id = e.child_node_id
                AND cnl.run_id = {$this->run_id}
            WHERE e.run_id = {$this->run_id}
                AND e.is_tree = 0
                AND e.strength = 'strong'
                AND e.link_name = " . $this->db->quote($link_name) . "
                AND cnl.size = {$size}
            LIMIT 3
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $samples = [];
        foreach ($obj_rows as $r) {
            $label = $r['class_name']
                ?? $r['location_type']
                ?? 'unknown';
            $samples[] = "{$label} ({$r['size']}B)";
        }

        return [
            'type' => 'object',
            'samples' => $samples,
            'identical_count' => 0,
        ];
    }
}
