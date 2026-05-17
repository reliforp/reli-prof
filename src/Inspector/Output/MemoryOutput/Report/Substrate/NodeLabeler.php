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
 *
 * Both sources are populated on {@see GraphSubstrate} during load
 * (PDO loaders read `context_node_attributes` + the nodes/edges/
 * locations join; binary loaders read the corresponding sections).
 * The labeler is a thin formatter on top — pure CPU, no SQL, no I/O.
 */
final class NodeLabeler
{
    /** @var array<int, string> node_id => "function_name:lineno" (Call Stack form) */
    private array $frame_labels_with_line = [];

    /** @var array<int, string> node_id => "function_name()" (path form) */
    private array $frame_labels_path_form = [];

    public function __construct(
        private GraphSubstrate $substrate,
    ) {
        foreach ($this->substrate->frame_labels as $node_id => $combined) {
            [$fn, $ln] = self::splitFunctionNameAndLineno($combined);
            if ($fn === '') {
                continue;
            }
            $this->frame_labels_with_line[$node_id] = $ln !== null
                ? "{$fn}:{$ln}"
                : $fn;
            $this->frame_labels_path_form[$node_id] = self::pathFormForFunction($fn);
        }
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
     */
    public function resolvePathLabel(
        string $link_name,
        int $child_node_id,
        bool $include_call_site = false,
    ): string {
        if ($include_call_site) {
            if (isset($this->frame_labels_with_line[$child_node_id])) {
                return $this->frame_labels_with_line[$child_node_id];
            }
        } else {
            if (isset($this->frame_labels_path_form[$child_node_id])) {
                return $this->frame_labels_path_form[$child_node_id];
            }
        }

        $canonical = $this->substrate->getCanonicalName($child_node_id);
        if ($canonical !== null) {
            return $canonical;
        }

        return $link_name;
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
}
