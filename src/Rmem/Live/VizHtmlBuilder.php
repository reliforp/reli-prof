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

namespace Reli\Rmem\Live;

use Reli\Inspector\Output\MemoryOutput\Report\Substrate\GraphSubstrate;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\PathFormatter;
use Reli\Rmem\Explore\RmemModel;

/**
 * Build the standalone viz HTML (and its data payload) from an
 * already-loaded RmemModel + GraphSubstrate. Shared between the
 * static rmem:viz command and the live HTTP server in rmem:live.
 */
final class VizHtmlBuilder
{
    public const DEFAULT_MAX_NODES = 20000;
    public const DEFAULT_MAX_CHILDREN_PER_NODE = 100;
    private const PATH_DEPTH_LIMIT = 64;

    /**
     * @param array<string, mixed> $extraPayload  Merged into the embedded
     *        DATA payload (e.g. live-mode endpoint hints).
     */
    public static function build(
        RmemModel $model,
        GraphSubstrate $substrate,
        string $sourceFile,
        int $top,
        int $depth,
        bool $allEdges,
        bool $liveMode = false,
        array $extraPayload = [],
        int $maxNodes = self::DEFAULT_MAX_NODES,
        int $maxChildrenPerNode = self::DEFAULT_MAX_CHILDREN_PER_NODE,
    ): string {
        [$nodes, $treeEdges, $refEdges] = self::buildSubgraph(
            $model,
            $substrate,
            $top,
            $depth,
            $allEdges,
            $maxNodes,
            $maxChildrenPerNode,
        );

        $payload = array_merge([
            'source' => basename($sourceFile),
            'node_count_total' => $model->nodeCount,
            'edge_count_total' => $model->edgeCount,
            'top' => $top,
            'depth' => $depth,
            'all_edges' => $allEdges,
            'nodes' => $nodes,
            'tree_edges' => $treeEdges,
            'ref_edges' => $refEdges,
        ], $extraPayload);

        $template = self::loadTemplate();
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        if ($json === false) {
            throw new \RuntimeException('json_encode failed: ' . json_last_error_msg());
        }

        $dataPlaceholder = '/*__RMEM_VIZ_DATA__*/null';
        $livePlaceholder = '/*__RMEM_VIZ_LIVE__*/false';
        if (!str_contains($template, $dataPlaceholder)) {
            throw new \RuntimeException('viz template is missing the data placeholder');
        }
        $html = str_replace($dataPlaceholder, $json, $template);
        $html = str_replace($livePlaceholder, $liveMode ? 'true' : 'false', $html);
        return $html;
    }

    /**
     * @return array{
     *   0: list<array{id:int,label:string,type:string,class:?string,retained:int,shallow:int,tree_parent:?int,link_name:?string,link_display:string,path:list<string>,root_link:?string}>,
     *   1: list<array{source:int,target:int,link:string}>,
     *   2: list<array{source:int,target:int}>,
     * }
     */
    public static function buildSubgraph(
        RmemModel $model,
        GraphSubstrate $substrate,
        int $top,
        int $depth,
        bool $allEdges,
        int $maxNodes = self::DEFAULT_MAX_NODES,
        int $maxChildrenPerNode = self::DEFAULT_MAX_CHILDREN_PER_NODE,
    ): array {
        $selected = [];
        $seeds = $model->getTopRetained($top);
        foreach ($seeds as $row) {
            $selected[$row['node_id']] = true;
        }

        // Ancestors: for each seed, walk tree parents up to the root so
        // the resulting hierarchy stays connected for treemap/sunburst.
        foreach (array_keys($selected) as $nodeId) {
            $cur = $substrate->getTreeParentNodeId($nodeId);
            while ($cur !== null && !isset($selected[$cur])) {
                $selected[$cur] = true;
                if (count($selected) >= $maxNodes) {
                    break 2;
                }
                $cur = $substrate->getTreeParentNodeId($cur);
            }
        }

        // Downward BFS up to $depth (tree edges only).
        // Downward expansion. The depth counter only ticks for edges
        // that represent a *meaningful* hop; structural intermediaries
        // (array_elements / object_properties / value / local_variables
        // / symbol_table / …) are free, matching how PathFormatter
        // collapses them. Without this, depth=1 from an Array lands on
        // ArrayElementsContext but misses the individual ArrayElement
        // leaves underneath, leaving scalar islands invisible.
        if ($depth > 0) {
            /** @var list<array{0:int,1:int}> stack of [nodeId, meaningfulDepth] */
            $stack = [];
            foreach (array_keys($selected) as $nid) {
                $stack[] = [$nid, 0];
            }
            while ($stack !== []) {
                /** @var array{0:int,1:int} $frame */
                $frame = array_pop($stack);
                [$cur, $meaningfulD] = $frame;
                // Cap per-parent fan-out: an ArrayElementsContext may
                // have hundreds of thousands of ArrayElement children,
                // which would both exhaust maxNodes for every other
                // branch and drown the browser. Keep the top-K by
                // retained (tie-broken deterministically by node_id).
                $children = $substrate->getChildren($cur);
                if (count($children) > $maxChildrenPerNode) {
                    usort(
                        $children,
                        static function (int $a, int $b) use ($substrate): int {
                            $sa = $substrate->getSubtreeSize($a);
                            $sb = $substrate->getSubtreeSize($b);
                            if ($sa !== $sb) {
                                return $sb <=> $sa;
                            }
                            return $a <=> $b;
                        },
                    );
                    $children = array_slice($children, 0, $maxChildrenPerNode);
                }
                foreach ($children as $childId) {
                    if (isset($selected[$childId])) {
                        continue;
                    }
                    $linkToChild = $substrate->getTreeLinkName($childId);
                    $isStructural = self::isStructuralHop($substrate, $childId, $linkToChild);
                    $nextD = $isStructural ? $meaningfulD : $meaningfulD + 1;
                    if ($nextD > $depth) {
                        continue;
                    }
                    $selected[$childId] = true;
                    $stack[] = [$childId, $nextD];
                    if (count($selected) >= $maxNodes) {
                        break 2;
                    }
                }
            }
        }

        $nodes = [];
        foreach (array_keys($selected) as $nodeId) {
            $treeParent = $substrate->getTreeParentNodeId($nodeId);
            $linkName = $substrate->getTreeLinkName($nodeId);
            $parentLink = $treeParent !== null ? $substrate->getTreeLinkName($treeParent) : null;
            $linkDisplay = self::formatLink($linkName, $parentLink, $nodeId, $model);
            $label = $model->nodeLabel($nodeId);
            if ($linkName !== null && $linkName !== '') {
                if ($parentLink === 'class_table') {
                    $label = 'class ' . $linkName;
                } elseif ($parentLink === 'function_table') {
                    $label = 'fn ' . $linkName;
                } elseif ($parentLink === 'array_elements' || $parentLink === 'object_properties') {
                    $label = $linkDisplay . ' ' . $label;
                }
            }
            $nodes[] = [
                'id' => $nodeId,
                'label' => $label,
                'type' => $substrate->getNodeType($nodeId) ?? '?',
                'class' => $model->resolveClassPublic($nodeId),
                'retained' => $substrate->getSubtreeSize($nodeId),
                'shallow' => $substrate->getNodeSize($nodeId),
                'tree_parent' => (isset($selected[$treeParent ?? -1])) ? $treeParent : null,
                'link_name' => $linkName,
                'link_display' => $linkDisplay,
                'path' => self::pathOf($substrate, $model, $nodeId),
                'root_link' => self::rootLinkOf($substrate, $nodeId),
            ];
        }

        $treeEdges = [];
        $refEdges = [];
        foreach (array_keys($selected) as $nodeId) {
            $treeChildren = $substrate->getChildren($nodeId);
            $treeChildSet = [];
            foreach ($treeChildren as $childId) {
                if (!isset($selected[$childId])) {
                    continue;
                }
                $treeChildSet[$childId] = true;
                $treeEdges[] = [
                    'source' => $nodeId,
                    'target' => $childId,
                    'link' => $substrate->getTreeLinkName($childId) ?? '',
                ];
            }
            if (!$allEdges) {
                continue;
            }
            foreach ($substrate->getAllChildren($nodeId) as $childId) {
                if (!isset($selected[$childId]) || isset($treeChildSet[$childId])) {
                    continue;
                }
                $refEdges[] = [
                    'source' => $nodeId,
                    'target' => $childId,
                ];
            }
        }

        return [$nodes, $treeEdges, $refEdges];
    }

    /**
     * Link names that represent PHP-internal wrappers (Bucket tables,
     * property slots, generic value indirection, variable tables). The
     * downward BFS treats these as "free hops" so depth=1 from an
     * Array still reaches each ArrayElement, even though the chain is
     * physically three edges long. Matches the STRUCTURAL list used by
     * {@see PathFormatter}.
     */
    private const STRUCTURAL_LINKS = [
        'array_elements',
        'object_properties',
        'value',
        'local_variables',
        'symbol_table',
        'global_variables',
        'dynamic_properties',
        'included_files',
        'object_handlers',
    ];

    /**
     * Node TYPES whose inbound edge is really a PHP-implementation
     * wrapper, even if the link name looks user-facing. Without this,
     * an Object → property → Array[scalars] chain runs out of BFS
     * depth at the ArrayHeaderContext (link = <prop_name>, counted as
     * one hop) and misses the ArrayElement leaves inside the array.
     * Treating ArrayHeaderContext as a free hop collapses that
     * wrapper level exactly how PathFormatter would.
     */
    private const STRUCTURAL_NODE_TYPES = [
        'ArrayHeaderContext',
        'ArrayPossibleOverheadContext',
    ];

    private static function isStructuralLink(?string $link): bool
    {
        return $link !== null && in_array($link, self::STRUCTURAL_LINKS, true);
    }

    private static function isStructuralHop(GraphSubstrate $substrate, int $childId, ?string $linkName): bool
    {
        if (self::isStructuralLink($linkName)) {
            return true;
        }
        $type = $substrate->getNodeType($childId);
        return $type !== null && in_array($type, self::STRUCTURAL_NODE_TYPES, true);
    }

    /**
     * Top-most ancestor's link_name (e.g. "call_frames", "class_table",
     * "objects_store", "function_table", "interned_strings"). Used as a
     * grouping key so the 3D Force view can visually cluster each forest
     * branch — otherwise heavy subtrees dominate the physics and small
     * islands fade out. Walks until the parent is null or the parent's
     * own link is "root_entry" (the synthetic top).
     */
    public static function rootLinkOf(GraphSubstrate $substrate, int $nodeId): ?string
    {
        $cur = $nodeId;
        $last = $substrate->getTreeLinkName($cur);
        $depth = 0;
        while ($depth < self::PATH_DEPTH_LIMIT) {
            $parent = $substrate->getTreeParentNodeId($cur);
            if ($parent === null) {
                break;
            }
            $parentLink = $substrate->getTreeLinkName($parent);
            if ($parentLink === null || $parentLink === '' || $parentLink === 'root_entry') {
                break;
            }
            $cur = $parent;
            $last = $substrate->getTreeLinkName($cur);
            $depth++;
        }
        return $last;
    }

    public static function formatLink(
        ?string $linkName,
        ?string $parentLink,
        ?int $nodeId = null,
        ?RmemModel $model = null,
    ): string {
        if ($linkName === null || $linkName === '') {
            return '';
        }
        // Frame entries under call_frames come in as raw indices ("0",
        // "1", ...); the useful label is ClassName::method:line which the
        // model already computed and stashed in frameLabels.
        if ($parentLink === 'call_frames' && $nodeId !== null && $model !== null) {
            $frameLabel = $model->getFrameLabel($nodeId);
            if ($frameLabel !== null && $frameLabel !== '') {
                return $frameLabel;
            }
        }
        return match ($parentLink) {
            'array_elements' => "[{$linkName}]",
            'object_properties' => "->{$linkName}",
            'class_table' => "class {$linkName}",
            'function_table' => "fn {$linkName}",
            default => $linkName,
        };
    }

    /**
     * Walk tree parents back to the root, substitute frame labels for
     * raw call-frame indices, then render the whole chain with
     * PathFormatter::toPhpSyntax() — the same collapser
     * inspector:memory:report uses — so the detail panel shows paths
     * like `<main>:28::$messages[0]->structure->raw` instead of the
     * raw "root_entry › call_frames › 0 › …" chain.
     *
     * Returns a single-segment list so the existing JS (which joins
     * with ' › ') still renders one pretty line.
     *
     * @return list<string>
     */
    public static function pathOf(GraphSubstrate $substrate, RmemModel $model, int $nodeId): array
    {
        $names = [];
        $types = [];
        $cur = $nodeId;
        $depth = 0;
        while ($cur !== null && $depth < self::PATH_DEPTH_LIMIT) {
            $rawName = $substrate->getTreeLinkName($cur);
            if ($rawName !== null && $rawName !== '') {
                $parent = $substrate->getTreeParentNodeId($cur);
                $parentLink = $parent !== null ? $substrate->getTreeLinkName($parent) : null;
                if ($parentLink === 'call_frames') {
                    $frameLabel = $model->getFrameLabel($cur);
                    if ($frameLabel !== null && $frameLabel !== '') {
                        $rawName = $frameLabel;
                    }
                }
                $names[] = $rawName;
                $types[] = $substrate->getNodeType($cur) ?? '';
            }
            $cur = $substrate->getTreeParentNodeId($cur);
            $depth++;
        }
        $names = array_reverse($names);
        $types = array_reverse($types);
        if ($names === []) {
            return [];
        }
        return [PathFormatter::toPhpSyntax($names, $types)];
    }

    private static function loadTemplate(): string
    {
        $path = __DIR__ . '/../../../resources/templates/rmem_viz.html';
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("cannot load viz template: {$path}");
        }
        return $contents;
    }
}
