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
 *  1. **Call-frame labels** — for `CallFrameContext` nodes. The
 *     underlying data is `function_name` + `lineno`. The labeler
 *     renders them in two forms:
 *      - `function_name:lineno` (e.g. `collectAll:297`) — used by the
 *        Call Stack section, where the line number disambiguates
 *        which point in the function the snapshot caught.
 *      - `function_name()` (e.g. `collectAll()`) — used everywhere
 *        else (`bottleneck_path`, `Spine:`, Top Arrays paths, etc.),
 *        where the line number is incidental noise (`$sink` doesn't
 *        depend on which line `collectAll` happens to be paused at).
 *        The `()` makes the call-frame nature unambiguous against
 *        PHP's `Class::staticMethod::$staticProp` syntax.
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
    /** @var array<int, string> node_id => "function_name:lineno" (Call Stack form) */
    private array $frame_labels_with_line = [];

    /** @var array<int, string> node_id => "function_name()" (path form) */
    private array $frame_labels_path_form = [];

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
     *     frame labels (binary path), in `"function_name:lineno"` form
     *     (or just `"function_name"` when no lineno is recorded). The
     *     labeler splits each entry into the two display forms it uses.
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
     * Resolve a link_name for a child node into a human-readable label.
     *
     * For `CallFrameContext` nodes:
     *  - `$include_call_site = true`: returns `"functionName:lineno"`
     *    (the Call Stack form).
     *  - `$include_call_site = false` (default): returns
     *    `"functionName()"` (the path form). All path-rendering passes
     *    use this default.
     *
     * For `ClassDefinitionContext` / `*FunctionDefinitionContext` nodes
     * the canonical-name form is returned regardless of the flag (the
     * line number doesn't apply).
     *
     * Falls back to the raw `$link_name` when no override is available
     * for the node.
     *
     * @psalm-suppress MixedArrayAccess, MixedAssignment
     */
    public function resolvePathLabel(
        string $link_name,
        int $child_node_id,
        bool $include_call_site = false,
    ): string {
        $this->ensureLoaded();

        if ($include_call_site) {
            if (isset($this->frame_labels_with_line[$child_node_id])) {
                return $this->frame_labels_with_line[$child_node_id];
            }
        } else {
            if (isset($this->frame_labels_path_form[$child_node_id])) {
                return $this->frame_labels_path_form[$child_node_id];
            }
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
        // Binary path: split the pre-baked "function_name:lineno"
        // strings into the two display forms.
        if ($this->preloaded_frame_labels !== null) {
            foreach ($this->preloaded_frame_labels as $node_id => $combined) {
                [$fn, $ln] = self::splitFunctionNameAndLineno($combined);
                if ($fn === '') {
                    continue;
                }
                $this->frame_labels_with_line[$node_id] = $ln !== null
                    ? "{$fn}:{$ln}"
                    : $fn;
                $this->frame_labels_path_form[$node_id] = self::pathFormForFunction($fn);
            }
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
            $this->frame_labels_with_line[$node_id] = $ln !== ''
                ? "{$fn}:{$ln}"
                : $fn;
            $this->frame_labels_path_form[$node_id] = self::pathFormForFunction($fn);
        }
    }

    /**
     * Split a `"function_name:lineno"` combined label back into its parts.
     * Handles `Class::method:42` correctly (the last `:` precedes the
     * lineno; `::` separators inside the function name are preserved).
     * Returns `[name, null]` when no numeric lineno suffix is present.
     *
     * @return array{0: string, 1: ?int}
     */
    private static function splitFunctionNameAndLineno(string $combined): array
    {
        $colon = strrpos($combined, ':');
        if ($colon === false) {
            return [$combined, null];
        }
        $tail = substr($combined, $colon + 1);
        if ($tail === '' || !ctype_digit($tail)) {
            // Last colon isn't a lineno separator (e.g. `Class::method`
            // with no lineno suffix). Keep the full string as the
            // function name.
            return [$combined, null];
        }
        return [substr($combined, 0, $colon), (int)$tail];
    }

    /**
     * Path-form rendering for a call-frame function name. Wraps the
     * name in `()` so `collectAll()::$sink` reads unambiguously as a
     * call-frame scope rather than aliasing PHP's
     * `Class::staticMethod::$staticProp` syntax. Idempotent on names
     * that already end with `)` (synthetic closure names, future
     * decorations).
     */
    private static function pathFormForFunction(string $function_name): string
    {
        if (str_ends_with($function_name, ')')) {
            return $function_name;
        }
        return $function_name . '()';
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
        //
        // No `is_tree` filter on the `name` edge: when a class
        // registers after its name string has already been collected
        // (autoload, opcache-loaded interned strings, etc.), the
        // resulting edge is recorded as `is_tree = 0` (back-reference
        // to an existing node). Filtering by `is_tree = 1` would drop
        // ~half of the user-defined ClassDefinitionContexts in real
        // captures while keeping the internal classes that happened
        // to be discovered first via the class table walk. Each
        // ClassDefinitionContext / *FunctionDefinitionContext has at
        // most one `name`-link edge by construction, so dropping the
        // filter doesn't introduce duplicates.
        try {
            $rows = $this->db->query("
                SELECT cn.node_id, cnl.string_value
                FROM context_nodes cn
                JOIN context_edges name_edge
                    ON name_edge.parent_node_id = cn.node_id
                    AND name_edge.run_id = cn.run_id
                    AND name_edge.link_name = 'name'
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
