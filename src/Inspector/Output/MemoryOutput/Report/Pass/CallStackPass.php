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
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\NodeLabeler;

/**
 * Shows the call stack at the time of snapshot capture.
 */
final class CallStackPass implements PassInterface
{
    /**
     * @param array<int, string>|null $frame_labels Pre-loaded frame labels (binary path)
     * @param array<int, string>|null $canonical_names Pre-loaded class/
     *     method/function canonical names (binary path)
     */
    public function __construct(
        private \PDO $db,
        private int $run_id,
        private ?GraphSubstrate $substrate = null,
        private ?array $frame_labels = null,
        private ?array $canonical_names = null,
    ) {
    }

    /**
     * @return list<Finding>
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
     * Substrate-backed path: walks the in-memory node-type index to
     * find the (typically single) CallFramesContext node, then
     * iterates its tree children via the CSR. Each child's tree-edge
     * link_name is the frame number ("0", "1", ...) and the
     * NodeLabeler resolves the child node id to "function_name:lineno"
     * via its already-cached attribute pull.
     *
     * Replaces the 4-JOIN SQL query the SQL fallback uses; that one
     * showed up at ~6% of total report runtime on big captures.
     *
     * @return list<Finding>
     */
    private function analyzeWithSubstrate(): array
    {
        assert($this->substrate !== null);
        $callFramesNodeId = null;
        foreach ($this->substrate->iterateNodeSizes() as $node_id => $_) {
            if ($this->substrate->getNodeType($node_id) === 'CallFramesContext') {
                $callFramesNodeId = $node_id;
                break;
            }
        }
        if ($callFramesNodeId === null) {
            return [];
        }

        $labeler = new NodeLabeler(
            $this->db,
            $this->run_id,
            $this->frame_labels,
            $this->canonical_names,
        );
        $framesByNo = [];
        foreach ($this->substrate->getChildren($callFramesNodeId) as $child_id) {
            $link_name = $this->substrate->getTreeLinkName($child_id) ?? '?';
            $label = $labeler->resolvePathLabel($link_name, $child_id);
            // resolvePathLabel returns the link_name unchanged when
            // the child has no function_name attribute. That can't
            // happen for a real CallFrameContext but we handle it
            // gracefully so a malformed dump doesn't show "0", "1",
            // ... as the call stack.
            if ($label === $link_name) {
                $label = '?';
            }
            $framesByNo[(int)$link_name] = $label;
        }
        if ($framesByNo === []) {
            return [];
        }
        ksort($framesByNo);
        return $this->buildFindings(array_values($framesByNo));
    }

    /**
     * SQL fallback: used in Phase 2 when no substrate is built.
     *
     * @return list<Finding>
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedArgument
     */
    private function analyzeWithSql(): array
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

        return $this->buildFindings($frames);
    }

    /**
     * @param  list<string> $frames frame labels in call order
     * @return list<Finding>
     */
    private function buildFindings(array $frames): array
    {
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
