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
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\NodeLabeler;

final class TopStringsPass implements PassInterface
{
    public function __construct(
        private \PDO $db,
        private int $run_id,
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
        // 3-hop ancestor: grandparent -> parent -> link_name -> string
        $rows = $this->db->query("
            SELECT
                cnl.node_id,
                cnl.size,
                substr(cnl.string_value, 1, 80) as preview,
                e1.link_name as link1,
                e1.parent_node_id as parent1_id,
                cnl_p1.class_name as parent1_class,
                e2.link_name as link2,
                e2.parent_node_id as parent2_id,
                e3.link_name as link3
            FROM context_node_locations cnl
            LEFT JOIN context_edges e1
                ON e1.child_node_id = cnl.node_id
                AND e1.is_tree = 1
                AND e1.run_id = {$this->run_id}
            LEFT JOIN context_node_locations cnl_p1
                ON cnl_p1.node_id = e1.parent_node_id
                AND cnl_p1.run_id = {$this->run_id}
            LEFT JOIN context_edges e2
                ON e2.child_node_id = e1.parent_node_id
                AND e2.is_tree = 1
                AND e2.run_id = {$this->run_id}
            LEFT JOIN context_edges e3
                ON e3.child_node_id = e2.parent_node_id
                AND e3.is_tree = 1
                AND e3.run_id = {$this->run_id}
            WHERE cnl.run_id = {$this->run_id}
                AND cnl.location_type = 'ZendStringMemoryLocation'
            ORDER BY cnl.size DESC
            LIMIT 10
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $labeler = new NodeLabeler($this->db, $this->run_id);
        $findings = [];
        foreach ($rows as $row) {
            $size = (int)$row['size'];
            if ($size < 10240) {
                continue;
            }

            $path = $this->buildOwnerPath($row, $labeler);
            $preview = $row['preview'] ?? '';
            if (strlen($preview) > 60) {
                $preview = substr($preview, 0, 57) . '...';
            }

            $findings[] = new Finding(
                kind: 'large_string',
                severity: $size > 102400
                    ? FindingSeverity::Medium
                    : FindingSeverity::Low,
                confidence: FindingConfidence::High,
                summary: sprintf(
                    '%.2f KB — %s%s',
                    $size / 1024,
                    $path ? "{$path}: " : '',
                    $preview ?: '(binary)',
                ),
                facts: [
                    'node_id' => (int)$row['node_id'],
                    'size' => $size,
                    'owner_path' => $path,
                    'preview' => $preview,
                ],
                impact_bytes: $size,
                evidence_node_ids: [(int)$row['node_id']],
            );
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $row
     * @psalm-suppress MixedArgument, MixedAssignment, RiskyTruthyFalsyComparison, InvalidArgument
     */
    private function buildOwnerPath(
        array $row,
        NodeLabeler $labeler,
    ): string {
        $parts = [];

        // 3rd hop (grandparent's parent link)
        if (($row['link3'] ?? null) !== null) {
            $parts[] = (string)$row['link3'];
        }

        // 2nd hop (parent's parent link), resolve frame labels
        if (($row['link2'] ?? null) !== null) {
            $parent2_id = (int)($row['parent2_id'] ?? 0);
            $parts[] = $labeler->resolvePathLabel(
                (string)$row['link2'],
                $parent2_id
            );
        }

        // 1st hop: prefer class_name, else link_name
        $parent_class = $row['parent1_class'] ?? null;
        if ($parent_class) {
            $short = preg_replace('/^.*\\\\/', '', $parent_class)
                ?? $parent_class;
            $parts[] = $short;
        }

        // Property link
        if (($row['link1'] ?? null) !== null) {
            $parts[] = (string)$row['link1'];
        }

        return implode(' -> ', $parts);
    }
}
