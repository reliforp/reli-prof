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

final class NonTreeEdgePass implements PassInterface
{
    /**
     * @param list<array{
     *     link_name: string,
     *     ref_count: int,
     *     target_count: int,
     *     sample_parent_node_id: int,
     *     sample_child_node_id: int
     * }>|null $precomputed_edge_stats
     */
    public function __construct(
        private \PDO $db,
        private int $run_id,
        private ?GraphSubstrate $substrate = null,
        private ?array $precomputed_edge_stats = null,
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
     * @return list<Finding>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument, MixedOperand, InvalidOperand
     * @psalm-suppress PossiblyInvalidArgument, InvalidArgument, RiskyTruthyFalsyComparison
     * @psalm-suppress MixedArgumentTypeCoercion
     */
    private function analyzeWithSql(): array
    {
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
            $finding = $this->buildSharedFinding(
                (string)$row['link_name'],
                (int)$row['ref_count'],
                (int)$row['target_count'],
                (string)($row['source_class'] ?? ''),
                (string)($row['target_class'] ?? ''),
            );
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument, MixedOperand, InvalidOperand
     * @psalm-suppress PossiblyInvalidArgument, InvalidArgument, RiskyTruthyFalsyComparison
     * @psalm-suppress MixedArgumentTypeCoercion
     */
    private function analyzeWithSubstrate(): array
    {
        assert($this->substrate !== null);

        if ($this->precomputed_edge_stats !== null) {
            $rows = $this->precomputed_edge_stats;
        } else {
            $rows = $this->db->query("
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
        }

        $findings = [];
        foreach ($rows as $row) {
            $link_name = (string)$row['link_name'];
            $source_class = $this->resolveDirectSourceClass(
                (int)$row['sample_parent_node_id'],
            ) ?? '';
            $target_class = $this->substrate->getNodeClass(
                (int)$row['sample_child_node_id'],
            ) ?? '';

            $finding = $this->buildSharedFinding(
                $link_name,
                (int)$row['ref_count'],
                (int)$row['target_count'],
                $source_class,
                $target_class,
            );
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    private function buildSharedFinding(
        string $link_name,
        int $ref_count,
        int $target_count,
        string $source_class,
        string $target_class,
    ): ?Finding {
        if (ctype_digit($link_name)) {
            return null;
        }

        $avg_refs = round($ref_count / max(1, $target_count), 1);
        $qualified = $source_class !== ''
            ? "{$source_class}::\${$link_name}"
            : $link_name;
        $target_label = $target_class !== '' ? " ({$target_class})" : '';

        if ($target_count === 1 && $ref_count > 50) {
            return new Finding(
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
                    'source_class' => $source_class !== '' ? $source_class : null,
                    'target_class' => $target_class !== '' ? $target_class : null,
                    'ref_count' => $ref_count,
                    'target_count' => $target_count,
                ],
            );
        }

        if ($target_count > 1 && $avg_refs > 2.0) {
            return new Finding(
                kind: 'shared_fanin',
                severity: FindingSeverity::Info,
                confidence: FindingConfidence::Medium,
                summary: sprintf(
                    '%s -> %s (%s refs -> %s targets, %.1f each)',
                    $qualified,
                    $target_class !== '' ? $target_class : '?',
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

        return null;
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
}
