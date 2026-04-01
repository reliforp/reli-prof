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

final class TopStringsPass implements PassInterface
{
    /** @var array<int, array{0: int, 1: string}>|null */
    private ?array $parentMapCache = null;
    /** @var array<int, string>|null */
    private ?array $nodeTypeCache = null;

    public function __construct(
        private \PDO $db,
        private int $run_id,
        private ?GraphSubstrate $substrate = null,
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

            $node_id = (int)$row['node_id'];
            $path = $this->substrate !== null
                ? $this->buildFullPath($node_id, $labeler)
                : $this->buildOwnerPath($row, $labeler);
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
     * Walk from node to root via tree parent edges, resolve with NodeLabeler + PathFormatter.
     * @psalm-suppress MixedArrayAccess, MixedAssignment
     */
    private function buildFullPath(int $node_id, NodeLabeler $labeler): string
    {
        assert($this->substrate !== null);

        // Build parent map with link_names (loaded once, cached in substrate wouldn't help here)
        if ($this->parentMapCache === null) {
            $this->parentMapCache = [];
            $rows = $this->db->query(
                "SELECT child_node_id, parent_node_id, link_name"
                . " FROM context_edges WHERE is_tree = 1"
                . " AND run_id = {$this->run_id}"
            )->fetchAll(\PDO::FETCH_NUM);
            foreach ($rows as $r) {
                $this->parentMapCache[(int)$r[0]] = [(int)($r[1] ?? -1), (string)$r[2]];
            }
            unset($rows);

            $this->nodeTypeCache = [];
            $rows = $this->db->query(
                "SELECT node_id, type FROM context_nodes"
                . " WHERE run_id = {$this->run_id}"
            )->fetchAll(\PDO::FETCH_NUM);
            foreach ($rows as $r) {
                $this->nodeTypeCache[(int)$r[0]] = (string)$r[1];
            }
            unset($rows);
        }

        $parts = [];
        $types = [];
        $cur = $node_id;
        for ($i = 0; $i < 20; $i++) {
            if (!isset($this->parentMapCache[$cur])) {
                break;
            }
            [$parent, $link] = $this->parentMapCache[$cur];
            $resolved = $labeler->resolvePathLabel($link, $cur);
            array_unshift($parts, $resolved);
            array_unshift($types, $this->nodeTypeCache[$cur] ?? '');
            $cur = $parent;
        }

        return PathFormatter::toPhpSyntax($parts, $types);
    }

    private const STRUCTURAL_LINKS = [
        'object_properties',
        'array_elements',
        'local_variables',
        'symbol_table',
        'dynamic_properties',
        'value',
        'call_frames',
    ];

    /**
     * @param array<string, mixed> $row
     * @psalm-suppress MixedArgument, MixedAssignment, RiskyTruthyFalsyComparison, InvalidArgument
     */
    private function buildOwnerPath(
        array $row,
        NodeLabeler $labeler,
    ): string {
        $raw_parts = [];

        if (($row['link3'] ?? null) !== null) {
            $raw_parts[] = (string)$row['link3'];
        }

        if (($row['link2'] ?? null) !== null) {
            $parent2_id = (int)($row['parent2_id'] ?? 0);
            $raw_parts[] = $labeler->resolvePathLabel(
                (string)$row['link2'],
                $parent2_id
            );
        }

        $parent_class = $row['parent1_class'] ?? null;
        if ($parent_class) {
            $short = preg_replace('/^.*\\\\/', '', $parent_class)
                ?? $parent_class;
            $raw_parts[] = $short;
        }

        if (($row['link1'] ?? null) !== null) {
            $raw_parts[] = (string)$row['link1'];
        }

        // Filter structural intermediaries and join with ->
        $filtered = array_values(array_filter(
            $raw_parts,
            fn(string $p) => !in_array($p, self::STRUCTURAL_LINKS, true)
        ));

        return implode('->', $filtered);
    }
}
