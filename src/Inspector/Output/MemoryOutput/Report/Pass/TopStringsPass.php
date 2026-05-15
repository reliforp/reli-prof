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
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\PathFormatter;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\SizeFormatter;

final class TopStringsPass implements PassInterface
{
    /**
     * @param list<array{node_id: int, size: int, preview: string}>|null $top_strings_data Pre-computed (binary path)
     * @param array<int, string>|null $frame_labels Pre-loaded frame labels (binary path)
     * @param array<int, string>|null $canonical_names Pre-loaded class/
     *     method/function canonical names (binary path)
     */
    public function __construct(
        private \PDO $db,
        private int $run_id,
        private GraphSubstrate $substrate,
        private ?array $top_strings_data = null,
        private ?array $frame_labels = null,
        private ?array $canonical_names = null,
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
        // Find the top 10 largest strings first — no joins, no per-row work.
        // The previous implementation joined 3 hops of context_edges before
        // applying ORDER BY/LIMIT, which forced the planner to compute the
        // join for every ZendStringMemoryLocation row in the table. On large
        // captures (15+ GB DBs, tens of millions of strings) that single
        // query dominated the entire report runtime. Sorting first and
        // walking ancestors only for the top 10 reduces the cost by orders
        // of magnitude. The covering index
        // `idx_context_node_locations_run_type_size` lets this run as a
        // pure index scan.
        if ($this->top_strings_data !== null) {
            $rows = [];
            foreach ($this->top_strings_data as $item) {
                $rows[] = [
                    'node_id' => $item['node_id'],
                    'size' => $item['size'],
                    'preview' => $item['preview'],
                ];
            }
        } else {
            // Same region filter as BinaryReportDataProvider::getTopStrings:
            // a dangling stream_memory_data->data dereferenced as a
            // zend_string lands in 'outside' with a multi-exabyte `size`
            // and would otherwise dominate this ranking.
            $rows = $this->db->query("
                SELECT
                    node_id,
                    size,
                    substr(string_value, 1, 80) as preview
                FROM context_node_locations
                WHERE run_id = {$this->run_id}
                    AND location_type = 'ZendStringMemoryLocation'
                    AND (region IN ('zend_mm_heap', 'zend_mm_huge', 'vm_stack', 'compiler_arena')
                         OR region IS NULL)
                ORDER BY size DESC
                LIMIT 10
            ")->fetchAll(\PDO::FETCH_ASSOC);
        }

        $labeler = new NodeLabeler(
            $this->db,
            $this->run_id,
            $this->frame_labels,
            $this->canonical_names,
        );
        $findings = [];
        foreach ($rows as $row) {
            $size = (int)$row['size'];
            if ($size < 10240) {
                continue;
            }

            $node_id = (int)$row['node_id'];
            $path = $this->buildFullPath($node_id, $labeler);
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
                    '%s — %s%s',
                    SizeFormatter::format($size),
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
     * Walk from node to root via tree parent edges, resolved entirely
     * from the substrate's in-memory tree-link / parent / type indexes.
     */
    private function buildFullPath(int $node_id, NodeLabeler $labeler): string
    {
        $parts = [];
        $types = [];
        $cur = $node_id;
        for ($i = 0; $i < 20; $i++) {
            $link = $this->substrate->getTreeLinkName($cur);
            if ($link === null) {
                break;
            }
            $parent = $this->substrate->getTreeParentNodeId($cur);
            if ($parent === null) {
                array_unshift($parts, $link);
                array_unshift($types, '');
                break;
            }
            array_unshift($parts, $labeler->resolvePathLabel($link, $cur));
            array_unshift($types, $this->substrate->getNodeType($cur) ?? '');
            $cur = $parent;
        }
        return PathFormatter::toPhpSyntax($parts, $types);
    }
}
