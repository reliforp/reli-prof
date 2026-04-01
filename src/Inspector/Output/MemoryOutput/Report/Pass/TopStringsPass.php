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

final class TopStringsPass implements PassInterface
{
    public function __construct(
        private \PDO $db,
        private int $run_id,
    ) {
    }

    /**
     * @return list<Finding>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument, MixedOperand, InvalidOperand
     * @psalm-suppress PossiblyInvalidArgument, InvalidArgument
     */
    #[\Override]
    public function analyze(): array
    {
        $rows = $this->db->query("
            SELECT
                cnl.node_id,
                cnl.size,
                substr(cnl.string_value, 1, 80) as preview,
                e.link_name as parent_link,
                cnl_parent.class_name as parent_class
            FROM context_node_locations cnl
            LEFT JOIN context_edges e ON e.child_node_id = cnl.node_id
                AND e.is_tree = 1 AND e.run_id = {$this->run_id}
            LEFT JOIN context_node_locations cnl_parent ON cnl_parent.node_id = e.parent_node_id
                AND cnl_parent.run_id = {$this->run_id}
            WHERE cnl.run_id = {$this->run_id}
                AND cnl.location_type = 'ZendStringMemoryLocation'
            ORDER BY cnl.size DESC
            LIMIT 10
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $findings = [];
        foreach ($rows as $row) {
            $size = (int)$row['size'];
            if ($size < 10240) {
                continue;
            }

            $parent_class = $row['parent_class']
                ? preg_replace('/^.*\\\\/', '', $row['parent_class'])
                : null;
            $context = implode(' -> ', array_filter([$parent_class, $row['parent_link']]));
            $preview = $row['preview'] ?? '';
            if (strlen($preview) > 60) {
                $preview = substr($preview, 0, 57) . '...';
            }

            $findings[] = new Finding(
                kind: 'large_string',
                severity: $size > 102400 ? FindingSeverity::Medium : FindingSeverity::Low,
                confidence: FindingConfidence::High,
                summary: sprintf(
                    '%.2f KB string — %s%s',
                    $size / 1024,
                    $context ? "{$context}: " : '',
                    $preview ?: '(binary)',
                ),
                facts: [
                    'node_id' => (int)$row['node_id'],
                    'size' => $size,
                    'owner' => $context,
                    'preview' => $preview,
                ],
                impact_bytes: $size,
                evidence_node_ids: [(int)$row['node_id']],
                replay_query: "SELECT node_id, size, substr(string_value, 1, 80) FROM context_node_locations"
                    . " WHERE location_type = 'ZendStringMemoryLocation' ORDER BY size DESC LIMIT 10",
            );
        }

        return $findings;
    }
}
