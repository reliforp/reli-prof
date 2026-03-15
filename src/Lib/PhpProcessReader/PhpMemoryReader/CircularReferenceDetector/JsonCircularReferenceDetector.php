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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\CircularReferenceDetector;

final class JsonCircularReferenceDetector
{
    /** @var list<array<string, mixed>> */
    private array $found_cycles = [];

    /** @var array<int, array{link_name: string, node: array<string, mixed>}> */
    private array $current_path = [];

    /** @var array<int, true> */
    private array $node_ids_on_stack = [];

    /**
     * @param array<string, mixed> $context_tree
     * @return array<string, mixed>
     */
    public function detect(array $context_tree): array
    {
        $this->found_cycles = [];
        $this->current_path = [];
        $this->node_ids_on_stack = [];

        $this->walk($context_tree);

        return [
            'circular_reference_count' => count($this->found_cycles),
            'circular_references' => $this->found_cycles,
        ];
    }

    /**
     * @param array<string, mixed> $tree
     */
    private function walk(array $tree): void
    {
        foreach ($tree as $link_name => $node) {
            if (!is_array($node)) {
                continue;
            }

            if (isset($node['#reference_node_id'])) {
                $ref_id = $node['#reference_node_id'];
                if (isset($this->node_ids_on_stack[$ref_id])) {
                    $this->recordCycle($ref_id, (string)$link_name);
                }
                continue;
            }

            if (!isset($node['#node_id'])) {
                $this->walk($node);
                continue;
            }

            $node_id = $node['#node_id'];
            $this->current_path[] = ['link_name' => (string)$link_name, 'node' => $node];
            $this->node_ids_on_stack[$node_id] = true;

            $children = array_filter(
                $node,
                fn ($key) => !str_starts_with((string)$key, '#'),
                ARRAY_FILTER_USE_KEY,
            );
            $this->walk($children);

            array_pop($this->current_path);
            unset($this->node_ids_on_stack[$node_id]);
        }
    }

    private function recordCycle(int $target_node_id, string $back_edge_link_name): void
    {
        $cycle_path = [];
        $found_start = false;
        foreach ($this->current_path as $entry) {
            if (!$found_start && ($entry['node']['#node_id'] ?? null) === $target_node_id) {
                $found_start = true;
            }
            if ($found_start) {
                $cycle_path[] = $this->summarizeNode($entry['link_name'], $entry['node']);
            }
        }

        if ($cycle_path === []) {
            return;
        }

        $total_size = 0;
        foreach ($cycle_path as $node_summary) {
            $total_size += $node_summary['size'];
        }

        $this->found_cycles[] = [
            'cycle_length' => count($cycle_path),
            'total_size' => $total_size,
            'back_edge' => $back_edge_link_name,
            'path' => $cycle_path,
        ];
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function summarizeNode(string $link_name, array $node): array
    {
        $result = [
            'link_name' => $link_name,
            'node_id' => $node['#node_id'] ?? null,
            'type' => $node['#type'] ?? 'unknown',
        ];

        $size = 0;
        $locations = $node['#locations'] ?? [];
        if (is_array($locations)) {
            foreach ($locations as $location) {
                if (is_array($location) && isset($location['size'])) {
                    $size += $location['size'];
                    if (isset($location['class_name'])) {
                        $result['class_name'] = $location['class_name'];
                    }
                    if (isset($location['address'])) {
                        $result['address'] = $location['address'];
                    }
                }
            }
        }
        $result['size'] = $size;

        return $result;
    }
}
