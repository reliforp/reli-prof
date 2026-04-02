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

/**
 * Detects objects that are likely GC-pending garbage:
 * reachable only via objects_store AND members of an SCC.
 *
 * These are candidates for "unreachable from user code but kept alive
 * by circular references, awaiting GC cycle collection".
 */
final class GcPendingPass implements PassInterface
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
     * @psalm-suppress MixedOperand, InvalidOperand
     */
    #[\Override]
    public function analyze(): array
    {
        if ($this->substrate->scc_profiles === []) {
            return [];
        }

        // Find which root branch each node belongs to (BFS from roots)
        $root_link_names = $this->loadRootLinkNames();
        $node_root_owner = [];

        foreach ($this->substrate->roots as $root) {
            $queue = [$root];
            $qi = 0;
            $node_root_owner[$root] = $root;
            while ($qi < count($queue)) {
                $node = $queue[$qi++];
                foreach ($this->substrate->children[$node] ?? [] as $child) {
                    if (!isset($node_root_owner[$child])) {
                        $node_root_owner[$child] = $root;
                        $queue[] = $child;
                    }
                }
            }
        }

        // Find the objects_store root
        $objects_store_root = null;
        foreach ($this->substrate->roots as $root) {
            if (($root_link_names[$root] ?? '') === 'objects_store') {
                $objects_store_root = $root;
                break;
            }
        }

        if ($objects_store_root === null) {
            return [];
        }

        // Collect SCC members owned by objects_store
        $gc_candidates = [];
        foreach ($this->substrate->node_to_scc as $node => $scc_id) {
            $owner = $node_root_owner[$node] ?? null;
            if ($owner !== $objects_store_root) {
                continue;
            }
            $cls = $this->substrate->node_classes[$node] ?? null;
            if ($cls === null) {
                continue;
            }
            if (!isset($gc_candidates[$cls])) {
                $gc_candidates[$cls] = ['count' => 0, 'size' => 0];
            }
            $gc_candidates[$cls]['count']++;
            $gc_candidates[$cls]['size']
                += $this->substrate->node_sizes[$node] ?? 0;
        }

        if ($gc_candidates === []) {
            return [];
        }

        arsort($gc_candidates);
        $total_count = 0;
        $total_size = 0;
        $class_list = [];
        foreach ($gc_candidates as $cls => $info) {
            $total_count += $info['count'];
            $total_size += $info['size'];
            $class_list[] = sprintf(
                '%dx %s',
                $info['count'],
                $cls
            );
        }

        $top_classes = implode(
            ', ',
            array_slice($class_list, 0, 5)
        );
        if (count($class_list) > 5) {
            $top_classes .= sprintf(
                ', ... +%d more',
                count($class_list) - 5
            );
        }

        return [
            new Finding(
                kind: 'gc_pending_candidate',
                severity: FindingSeverity::Medium,
                confidence: FindingConfidence::Low,
                summary: sprintf(
                    '%s objects (%s) reachable only via objects_store',
                    number_format($total_count),
                    SizeFormatter::format($total_size),
                ),
                facts: [
                    'total_count' => $total_count,
                    'total_size' => $total_size,
                    'classes' => $gc_candidates,
                ],
                hypothesis: "Classes: {$top_classes}\n"
                    . 'These MAY be unreachable from user code and'
                    . " awaiting GC cycle collection.\n"
                    . 'Note: reli may not track all reference paths;'
                    . ' false positive possible.',
                next_checks: [
                    'If this grows across snapshots, it indicates a leak',
                    'Break circular references for deterministic cleanup',
                ],
                impact_bytes: $total_size,
            ),
        ];
    }

    /**
     * @return array<int, string> root_node_id => link_name
     * @psalm-suppress MixedArrayAccess, MixedAssignment
     */
    private function loadRootLinkNames(): array
    {
        $rows = $this->db->query(
            "SELECT child_node_id, link_name FROM context_edges"
            . " WHERE parent_node_id IS NULL AND is_tree = 1"
            . " AND run_id = {$this->run_id}"
        )->fetchAll(\PDO::FETCH_NUM);

        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r[0]] = (string)$r[1];
        }
        return $map;
    }
}
