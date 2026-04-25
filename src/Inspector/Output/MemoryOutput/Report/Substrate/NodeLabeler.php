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

namespace Reli\Inspector\Output\MemoryOutput\Report\Substrate;

/**
 * Resolves node IDs to human-readable labels for report output.
 *
 * Handles two label sources:
 *
 *  1. **Call-frame labels** — `function_name:lineno` for `CallFrameContext`
 *     nodes, derived from `context_node_attributes`.
 *
 *  2. **Canonical class / method / function names** — the `name` child of
 *     `ClassDefinitionContext` / `UserFunctionDefinitionContext` /
 *     `InternalFunctionDefinitionContext` nodes. The HashTable bucket key
 *     that the collector emits as the link_name is case-folded
 *     ("twig\extension\coreextension"); the canonical name lives in a
 *     `name` child string node ("Twig\Extension\CoreExtension"). This
 *     labeler returns the canonical form at display time without
 *     touching the underlying substrate.
 */
final class NodeLabeler
{
    /** @var array<int, string> node_id => "function_name:lineno" */
    private array $frame_labels = [];

    /**
     * node_id => canonical class / method / function name. Populated for
     * `ClassDefinitionContext` and `*FunctionDefinitionContext` nodes
     * from their `name` string child.
     *
     * @var array<int, string>
     */
    private array $canonical_names = [];

    /** @var bool */
    private bool $loaded = false;

    /**
     * @param array<int, string>|null $preloaded_frame_labels Pre-loaded
     *     frame labels (binary path).
     * @param array<int, string>|null $preloaded_canonical_names Pre-loaded
     *     canonical class/method/function names (binary path). When
     *     provided, the SQL fetch is skipped.
     */
    public function __construct(
        private ?\PDO $db,
        private int $run_id,
        private ?array $preloaded_frame_labels = null,
        private ?array $preloaded_canonical_names = null,
    ) {
    }

    /**
     * Resolve a link_name like "1" into "functionName:42" (call frame),
     * or a case-folded class/method/function key into its canonical name.
     * Falls back to the raw link_name when no override is available.
     *
     * @psalm-suppress MixedArrayAccess, MixedAssignment
     */
    public function resolvePathLabel(
        string $link_name,
        int $child_node_id,
    ): string {
        $this->ensureLoaded();

        if (isset($this->frame_labels[$child_node_id])) {
            return $this->frame_labels[$child_node_id];
        }

        if (isset($this->canonical_names[$child_node_id])) {
            return $this->canonical_names[$child_node_id];
        }

        return $link_name;
    }

    /** @psalm-suppress MixedArrayAccess, MixedAssignment, MixedPropertyTypeCoercion */
    private function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        $this->loadFrameLabels();
        $this->loadCanonicalNames();
    }

    /** @psalm-suppress MixedArrayAccess, MixedAssignment */
    private function loadFrameLabels(): void
    {
        // Binary path: use pre-loaded frame labels
        if ($this->preloaded_frame_labels !== null) {
            $this->frame_labels = $this->preloaded_frame_labels;
            return;
        }

        // SQL path
        if ($this->db === null) {
            return;
        }

        $rows = $this->db->query("
            SELECT node_id, \"key\", value
            FROM context_node_attributes
            WHERE run_id = {$this->run_id}
                AND \"key\" IN ('function_name', 'lineno')
        ")->fetchAll(\PDO::FETCH_NUM);

        /** @var array<int, array{function_name?:string, lineno?:string}> $by_node */
        $by_node = [];
        foreach ($rows as $row) {
            $node_id = (int)$row[0];
            $key = (string)$row[1];
            $value = $row[2] === null ? '' : (string)$row[2];
            if ($key === 'function_name') {
                $by_node[$node_id]['function_name'] = $value;
            } elseif ($key === 'lineno') {
                $by_node[$node_id]['lineno'] = $value;
            }
        }

        foreach ($by_node as $node_id => $kvs) {
            $fn = $kvs['function_name'] ?? '';
            if ($fn === '') {
                continue;
            }
            $ln = $kvs['lineno'] ?? '';
            $this->frame_labels[$node_id] = $ln !== '' ? "{$fn}:{$ln}" : $fn;
        }
    }

    /**
     * Build the `node_id => canonical_name` map for class definitions and
     * function/method definitions. The collector emits a `name` child
     * string node alongside each definition; that string is the canonical
     * (case-preserved) identifier the source declared.
     *
     * @psalm-suppress MixedArrayAccess, MixedAssignment
     */
    private function loadCanonicalNames(): void
    {
        if ($this->preloaded_canonical_names !== null) {
            $this->canonical_names = $this->preloaded_canonical_names;
            return;
        }

        if ($this->db === null) {
            return;
        }

        // The binary report path reuses NodeLabeler with a dummy
        // in-memory PDO (no schema) and is expected to preload
        // canonical_names instead. If preload was skipped *and* the db
        // happens to lack the schema, fall through silently so the
        // labeler still answers raw link_names rather than blowing up
        // the surrounding pass.
        try {
            $rows = $this->db->query("
                SELECT cn.node_id, cnl.string_value
                FROM context_nodes cn
                JOIN context_edges name_edge
                    ON name_edge.parent_node_id = cn.node_id
                    AND name_edge.run_id = cn.run_id
                    AND name_edge.link_name = 'name'
                    AND name_edge.is_tree = 1
                JOIN context_node_locations cnl
                    ON cnl.node_id = name_edge.child_node_id
                    AND cnl.run_id = name_edge.run_id
                    AND cnl.location_type = 'ZendStringMemoryLocation'
                WHERE cn.run_id = {$this->run_id}
                    AND cn.type IN (
                        'ClassDefinitionContext',
                        'UserFunctionDefinitionContext',
                        'InternalFunctionDefinitionContext'
                    )
                    AND cnl.string_value IS NOT NULL
                    AND cnl.string_value <> ''
            ")->fetchAll(\PDO::FETCH_NUM);
        } catch (\PDOException) {
            return;
        }

        foreach ($rows as $row) {
            $node_id = (int)$row[0];
            $name = (string)$row[1];
            $this->canonical_names[$node_id] = $name;
        }
    }
}
