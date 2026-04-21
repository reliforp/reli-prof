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
    public const MAX_NODES = 5000;
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
    ): string {
        [$nodes, $treeEdges, $refEdges] = self::buildSubgraph(
            $model,
            $substrate,
            $top,
            $depth,
            $allEdges,
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
     *   0: list<array{id:int,label:string,type:string,class:?string,retained:int,shallow:int,tree_parent:?int,link_name:?string,link_display:string,path:list<string>}>,
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
                if (count($selected) >= self::MAX_NODES) {
                    break 2;
                }
                $cur = $substrate->getTreeParentNodeId($cur);
            }
        }

        // Downward BFS up to $depth (tree edges only).
        if ($depth > 0) {
            $frontier = array_keys($selected);
            for ($d = 0; $d < $depth; $d++) {
                $next = [];
                foreach ($frontier as $nid) {
                    foreach ($substrate->getChildren($nid) as $childId) {
                        if (!isset($selected[$childId])) {
                            $selected[$childId] = true;
                            $next[] = $childId;
                            if (count($selected) >= self::MAX_NODES) {
                                break 3;
                            }
                        }
                    }
                }
                $frontier = $next;
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
