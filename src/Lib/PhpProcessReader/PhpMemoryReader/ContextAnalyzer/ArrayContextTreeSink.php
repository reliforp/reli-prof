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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer;

use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\EdgeStrength;
use Reli\Lib\Process\MemoryLocation;

final class ArrayContextTreeSink implements ContextTreeSink
{
    /** @var array<string, mixed> */
    private array $root = [];

    /** @var array<int, mixed> */
    private array $node_refs = [];

    /**
     * @param iterable<array-key, MemoryLocation> $locations
     * @param array<string, mixed> $attributes
     */
    #[\Override]
    public function emitNode(
        int $node_id,
        ?int $parent_node_id,
        string $link_name,
        string $type,
        iterable $locations,
        array $attributes,
        EdgeStrength $edge_strength = EdgeStrength::Strong,
    ): void {
        $node = [
            '#node_id' => $node_id,
            '#type' => $type,
            '#edge_strength' => $edge_strength->value,
        ];

        if ($locations instanceof \Traversable) {
            $locations_array = iterator_to_array($locations);
        } else {
            $locations_array = $locations;
        }
        if ($locations_array !== []) {
            $node['#locations'] = $locations_array;
        }

        $node += $attributes;

        if ($parent_node_id === null) {
            $this->root[$link_name] = $node;
            /** @psalm-suppress UnsupportedPropertyReferenceUsage */
            $this->node_refs[$node_id] = &$this->root[$link_name];
        } else {
            /** @psalm-suppress MixedArrayAssignment */
            $this->node_refs[$parent_node_id][$link_name] = $node;
            /** @psalm-suppress UnsupportedPropertyReferenceUsage, MixedArrayAssignment */
            $this->node_refs[$node_id] = &$this->node_refs[$parent_node_id][$link_name];
        }
    }

    #[\Override]
    public function emitReference(
        int $reference_node_id,
        ?int $parent_node_id,
        string $link_name,
        EdgeStrength $edge_strength = EdgeStrength::Strong,
    ): void {
        $ref = [
            '#reference_node_id' => $reference_node_id,
            '#edge_strength' => $edge_strength->value,
        ];

        if ($parent_node_id === null) {
            $this->root[$link_name] = $ref;
        } else {
            /** @psalm-suppress MixedArrayAssignment */
            $this->node_refs[$parent_node_id][$link_name] = $ref;
        }
    }

    #[\Override]
    public function allowsRelease(): bool
    {
        return false;
    }

    /** @return array<string, mixed> */
    public function getResult(): array
    {
        return $this->root;
    }
}
