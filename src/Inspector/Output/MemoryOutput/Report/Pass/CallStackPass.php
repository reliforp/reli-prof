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

/**
 * Shows the call stack at the time of snapshot capture.
 */
final class CallStackPass implements PassInterface
{
    public function __construct(
        private \PDO $db,
        private int $run_id,
    ) {
    }

    /**
     * @return list<Finding>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument
     */
    #[\Override]
    public function analyze(): array
    {
        $rows = $this->db->query("
            SELECT
                e.link_name as frame_no,
                fn.value as function_name,
                ln.value as lineno
            FROM context_edges e
            JOIN context_nodes cn
                ON cn.node_id = e.child_node_id
                AND cn.type = 'CallFrameContext'
                AND cn.run_id = {$this->run_id}
            LEFT JOIN context_node_attributes fn
                ON fn.node_id = e.child_node_id
                AND fn.key = 'function_name'
                AND fn.run_id = {$this->run_id}
            LEFT JOIN context_node_attributes ln
                ON ln.node_id = e.child_node_id
                AND ln.key = 'lineno'
                AND ln.run_id = {$this->run_id}
            WHERE e.is_tree = 1
                AND e.run_id = {$this->run_id}
                AND e.parent_node_id IN (
                    SELECT cn2.node_id
                    FROM context_nodes cn2
                    WHERE cn2.type = 'CallFramesContext'
                        AND cn2.run_id = {$this->run_id}
                )
            ORDER BY cast(e.link_name as integer)
        ")->fetchAll(\PDO::FETCH_ASSOC);

        if ($rows === []) {
            return [];
        }

        $frames = [];
        foreach ($rows as $row) {
            $fn = $row['function_name'] ?? '?';
            $ln = $row['lineno'] ?? null;
            $frames[] = $ln !== null
                ? "{$fn}:{$ln}"
                : (string)$fn;
        }

        $lines = [];
        foreach ($frames as $i => $frame) {
            $lines[] = "#{$i} {$frame}";
        }

        return [
            new Finding(
                kind: 'call_stack',
                severity: FindingSeverity::Info,
                confidence: FindingConfidence::High,
                summary: 'Call stack: ' . implode(' -> ', $frames),
                facts: [
                    'frames' => $frames,
                    'depth' => count($frames),
                ],
                hypothesis: implode("\n", $lines),
            ),
        ];
    }
}
