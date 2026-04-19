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

namespace Reli\Rmem\Serve;

use Reli\Inspector\Output\MemoryOutput\Report\Substrate\SizeFormatter;
use Reli\Rmem\Explore\RmemModel;

/**
 * Budget-aware query service for rmem:serve protocol.
 *
 * Translates JSON requests into RmemModel calls with enforced
 * response limits and work budgets.
 */
final class RmemQueryService
{
    private const DEFAULT_LIMIT = 100;
    private const DEFAULT_RANKING_LIMIT = 50;
    private const PROTOCOL_VERSION = 1;

    public function __construct(
        private RmemModel $model,
        private string $rmemPath,
        private string $serverId,
    ) {
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function handle(array $request): array
    {
        $action = $request['action'] ?? '';

        try {
            return match ($action) {
                'server.hello' => $this->hello(),
                'server.ping' => ['ok' => true, 'data' => 'pong'],

                'query.roots' => $this->roots($request),
                'query.children' => $this->children($request),
                'query.parents' => $this->parents($request),
                'query.node_detail' => $this->nodeDetail($request),
                'query.sandwich' => $this->sandwich($request),
                'query.path_to_root' => $this->pathToRoot($request),
                'query.class_ranking' => $this->classRanking($request),
                'query.type_ranking' => $this->typeRanking($request),
                'query.top_retained' => $this->topRetained($request),
                'query.nodes_by_class' => $this->nodesByClass($request),
                'query.nodes_by_type' => $this->nodesByType($request),
                'query.find_by_address' => $this->findByAddress($request),
                'query.find_function_def' => $this->findFunctionDef($request),
                'query.find_class_def' => $this->findClassDef($request),
                'query.subtree_stats' => $this->subtreeStats($request),
                'query.search' => $this->search($request),
                'query.scc_for_node' => $this->sccForNode($request),
                'query.scc_ranking' => $this->sccRanking($request),

                default => ['ok' => false, 'error' => "Unknown action: {$action}"],
            };
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function hello(): array
    {
        return [
            'ok' => true,
            'data' => [
                'protocol_version' => self::PROTOCOL_VERSION,
                'server_id' => $this->serverId,
                'rmem_path' => $this->rmemPath,
                'node_count' => $this->model->nodeCount,
                'edge_count' => $this->model->edgeCount,
            ],
        ];
    }

    /** @param array<string, mixed> $req */
    private function roots(array $req): array
    {
        $rows = $this->model->getRootChildren();
        $limit = (int)($req['limit'] ?? self::DEFAULT_LIMIT);
        return $this->paginatedResponse($rows, $limit);
    }

    /** @param array<string, mixed> $req */
    private function children(array $req): array
    {
        $nodeId = (int)($req['node_id'] ?? -1);
        $allEdges = (bool)($req['all_edges'] ?? true);
        $sort = (string)($req['sort'] ?? 'retained');
        $limit = (int)($req['limit'] ?? self::DEFAULT_LIMIT);
        $rows = $this->model->getChildren($nodeId, $allEdges, $sort);
        return $this->paginatedResponse($rows, $limit);
    }

    /** @param array<string, mixed> $req */
    private function parents(array $req): array
    {
        $nodeId = (int)($req['node_id'] ?? -1);
        $limit = (int)($req['limit'] ?? self::DEFAULT_LIMIT);
        $rows = $this->model->getParents($nodeId);
        return $this->paginatedResponse($rows, $limit);
    }

    /** @param array<string, mixed> $req */
    private function nodeDetail(array $req): array
    {
        $nodeId = (int)($req['node_id'] ?? -1);
        $detail = $this->model->nodeDetail($nodeId);
        $detail['label'] = $this->model->nodeLabel($nodeId);
        $detail['node_id'] = $nodeId;
        $detail['scc_id'] = $this->model->getNodeSccId($nodeId);
        return ['ok' => true, 'data' => $detail];
    }

    /** @param array<string, mixed> $req */
    private function sandwich(array $req): array
    {
        $nodeId = (int)($req['node_id'] ?? -1);
        $limit = (int)($req['limit'] ?? self::DEFAULT_LIMIT);

        $parents = $this->model->getParents($nodeId);
        $children = $this->model->getChildren($nodeId, true);
        $detail = $this->model->nodeDetail($nodeId);
        $detail['label'] = $this->model->nodeLabel($nodeId);
        $detail['node_id'] = $nodeId;
        $detail['scc_id'] = $this->model->getNodeSccId($nodeId);

        $pTrunc = count($parents) > $limit;
        $cTrunc = count($children) > $limit;

        return [
            'ok' => true,
            'data' => [
                'parents' => array_slice($parents, 0, $limit),
                'detail' => $detail,
                'children' => array_slice($children, 0, $limit),
            ],
            'truncated' => ['parents' => $pTrunc, 'children' => $cTrunc],
        ];
    }

    /** @param array<string, mixed> $req */
    private function pathToRoot(array $req): array
    {
        $nodeId = (int)($req['node_id'] ?? -1);
        $path = $this->model->pathToRoot($nodeId);
        return ['ok' => true, 'data' => $path];
    }

    /** @param array<string, mixed> $req */
    private function classRanking(array $req): array
    {
        $limit = (int)($req['limit'] ?? self::DEFAULT_RANKING_LIMIT);
        $ranking = $this->model->getClassRanking();
        return $this->paginatedResponse($ranking, $limit);
    }

    /** @param array<string, mixed> $req */
    private function typeRanking(array $req): array
    {
        $limit = (int)($req['limit'] ?? self::DEFAULT_RANKING_LIMIT);
        $ranking = $this->model->getTypeRanking();
        return $this->paginatedResponse($ranking, $limit);
    }

    /** @param array<string, mixed> $req */
    private function topRetained(array $req): array
    {
        $limit = (int)($req['limit'] ?? self::DEFAULT_RANKING_LIMIT);
        $rows = $this->model->getTopRetained($limit);
        return ['ok' => true, 'data' => $rows, 'total_count' => count($rows), 'truncated' => false];
    }

    /** @param array<string, mixed> $req */
    private function nodesByClass(array $req): array
    {
        $class = (string)($req['class'] ?? '');
        $limit = (int)($req['limit'] ?? self::DEFAULT_LIMIT);
        if ($class === '') {
            return ['ok' => false, 'error' => 'Missing "class" parameter'];
        }
        $rows = $this->model->getNodesByClass($class);
        return $this->paginatedResponse($rows, $limit);
    }

    /** @param array<string, mixed> $req */
    private function nodesByType(array $req): array
    {
        $type = (string)($req['type'] ?? '');
        $limit = (int)($req['limit'] ?? self::DEFAULT_LIMIT);
        if ($type === '') {
            return ['ok' => false, 'error' => 'Missing "type" parameter'];
        }
        $rows = $this->model->getNodesByType($type);
        return $this->paginatedResponse($rows, $limit);
    }

    /** @param array<string, mixed> $req */
    private function findByAddress(array $req): array
    {
        $addrStr = (string)($req['address'] ?? '');
        if ($addrStr === '') {
            return ['ok' => false, 'error' => 'Missing "address" parameter'];
        }
        $addr = str_starts_with($addrStr, '0x')
            ? (int)hexdec(substr($addrStr, 2))
            : (int)$addrStr;
        $nodeId = $this->model->findNodeByAddress($addr);
        if ($nodeId === null) {
            return ['ok' => false, 'error' => "No node at address {$addrStr}"];
        }
        $detail = $this->model->nodeDetail($nodeId);
        $detail['label'] = $this->model->nodeLabel($nodeId);
        $detail['node_id'] = $nodeId;
        return ['ok' => true, 'data' => $detail];
    }

    /** @param array<string, mixed> $req */
    private function findFunctionDef(array $req): array
    {
        $name = (string)($req['name'] ?? '');
        if ($name === '') {
            return ['ok' => false, 'error' => 'Missing "name" parameter'];
        }
        $nodeId = $this->model->findFunctionDef($name);
        if ($nodeId === null) {
            return ['ok' => false, 'error' => "Function not found: {$name}"];
        }
        $detail = $this->model->nodeDetail($nodeId);
        $detail['label'] = $this->model->nodeLabel($nodeId);
        $detail['node_id'] = $nodeId;
        return ['ok' => true, 'data' => $detail];
    }

    /** @param array<string, mixed> $req */
    private function findClassDef(array $req): array
    {
        $name = (string)($req['name'] ?? '');
        if ($name === '') {
            return ['ok' => false, 'error' => 'Missing "name" parameter'];
        }
        $nodeId = $this->model->findClassDef($name);
        if ($nodeId === null) {
            return ['ok' => false, 'error' => "Class not found: {$name}"];
        }
        $detail = $this->model->nodeDetail($nodeId);
        $detail['label'] = $this->model->nodeLabel($nodeId);
        $detail['node_id'] = $nodeId;
        return ['ok' => true, 'data' => $detail];
    }

    /** @param array<string, mixed> $req */
    private function subtreeStats(array $req): array
    {
        $nodeId = (int)($req['node_id'] ?? -1);
        $maxNodes = (int)($req['max_nodes'] ?? 100000);
        $maxDepth = (int)($req['max_depth'] ?? 50);

        $typeGroups = [];
        $classGroups = [];
        $totalRetained = 0;
        $scanned = 0;
        $truncated = false;

        $stack = [[$nodeId, 0]];
        $visited = [];
        while ($stack) {
            [$nid, $depth] = array_pop($stack);
            if (isset($visited[$nid]) || $depth > $maxDepth) {
                continue;
            }
            $visited[$nid] = true;
            $scanned++;
            if ($scanned > $maxNodes) {
                $truncated = true;
                break;
            }

            $size = $this->model->nodeSize($nid);
            $totalRetained += $size;

            $type = $this->model->nodeType($nid);
            if (!isset($typeGroups[$type])) {
                $typeGroups[$type] = ['type' => $type, 'count' => 0, 'total' => 0];
            }
            $typeGroups[$type]['count']++;
            $typeGroups[$type]['total'] += $size;

            $class = $this->model->resolveClassPublic($nid);
            if ($class !== null) {
                if (!isset($classGroups[$class])) {
                    $classGroups[$class] = ['class' => $class, 'count' => 0, 'total' => 0];
                }
                $classGroups[$class]['count']++;
                $classGroups[$class]['total'] += $size;
            }

            foreach ($this->model->getChildrenRaw($nid) as $childId) {
                if (!isset($visited[$childId])) {
                    $stack[] = [$childId, $depth + 1];
                }
            }
        }

        usort($typeGroups, fn ($a, $b) => $b['total'] <=> $a['total']);
        usort($classGroups, fn ($a, $b) => $b['total'] <=> $a['total']);

        return [
            'ok' => true,
            'data' => [
                'total_retained' => $totalRetained,
                'node_count' => count($visited),
                'scanned_count' => $scanned,
                'type_breakdown' => array_values($typeGroups),
                'class_breakdown' => array_values($classGroups),
                'truncated' => $truncated,
            ],
        ];
    }

    /** @param array<string, mixed> $req */
    private function search(array $req): array
    {
        $pattern = (string)($req['pattern'] ?? '');
        $limit = (int)($req['limit'] ?? self::DEFAULT_LIMIT);
        if ($pattern === '') {
            return ['ok' => false, 'error' => 'Missing "pattern" parameter'];
        }
        $results = $this->model->globalSearch($pattern, $limit);
        return $this->paginatedResponse($results, $limit);
    }

    /** @param array<string, mixed> $req */
    private function sccForNode(array $req): array
    {
        $nodeId = (int)($req['node_id'] ?? -1);
        $sccId = $this->model->getNodeSccId($nodeId);
        if ($sccId === null) {
            $profiles = $this->model->getSccProfiles();
            if ($profiles === null) {
                return ['ok' => false, 'error_code' => 'not_ready', 'error' => 'SCC data not available'];
            }
            return ['ok' => true, 'data' => null, 'message' => 'Node is not in any SCC'];
        }
        $profile = $this->model->getSccProfile($sccId);
        if ($profile === null) {
            return ['ok' => false, 'error' => 'SCC profile not found'];
        }

        // Enrich members with labels and intra-SCC edges
        $memberSet = array_flip($profile['nodes']);
        $members = [];
        foreach ($profile['nodes'] as $nid) {
            $edges = [];
            foreach ($this->model->getSubstrate()->getAllChildren($nid) as $childId) {
                if (isset($memberSet[$childId]) && $childId !== $nid) {
                    $link = $this->model->getSubstrate()->getTreeLinkName($childId) ?? '?';
                    $edges[] = [
                        'target_node_id' => $childId,
                        'link_name' => $link,
                        'target_label' => $this->model->nodeLabel($childId),
                    ];
                }
            }
            $members[] = [
                'node_id' => $nid,
                'label' => $this->model->nodeLabel($nid),
                'shallow' => $this->model->nodeSize($nid),
                'edges_to_scc_members' => $edges,
            ];
        }

        return [
            'ok' => true,
            'data' => [
                'scc_id' => $sccId,
                'node_count' => $profile['node_count'],
                'total_size' => $profile['total_size'],
                'signature' => $profile['signature'] ?? '',
                'members' => $members,
            ],
        ];
    }

    /** @param array<string, mixed> $req */
    private function sccRanking(array $req): array
    {
        $limit = (int)($req['limit'] ?? self::DEFAULT_RANKING_LIMIT);
        $profiles = $this->model->getSccProfiles();
        if ($profiles === null) {
            return ['ok' => false, 'error_code' => 'not_ready', 'error' => 'SCC data not available'];
        }
        usort($profiles, fn ($a, $b) => $b['total_size'] <=> $a['total_size']);
        $data = [];
        foreach (array_slice($profiles, 0, $limit) as $p) {
            $data[] = [
                'scc_id' => $p['id'],
                'node_count' => $p['node_count'],
                'total_size' => $p['total_size'],
                'signature' => $p['signature'] ?? '',
            ];
        }
        return $this->paginatedResponse($data, $limit);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function paginatedResponse(array $rows, int $limit): array
    {
        $total = count($rows);
        $truncated = $total > $limit;
        return [
            'ok' => true,
            'data' => array_slice($rows, 0, $limit),
            'total_count' => $total,
            'truncated' => $truncated,
            'limit' => $limit,
        ];
    }
}
