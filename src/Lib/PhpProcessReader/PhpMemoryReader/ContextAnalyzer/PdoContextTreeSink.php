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

use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\RefcountedMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionBoundaries;
use Reli\Lib\Process\MemoryLocation;

final class PdoContextTreeSink implements ContextTreeSink
{
    private \PDOStatement $node_stmt;
    private \PDOStatement $location_stmt;
    private \PDOStatement $attr_stmt;

    public function __construct(
        \PDO $db,
        private ?RegionBoundaries $region_boundaries = null,
    ) {
        $this->node_stmt = $db->prepare(
            'INSERT OR IGNORE INTO context_nodes (node_id, parent_node_id, link_name, type, reference_node_id)'
            . ' VALUES (:node_id, :parent_node_id, :link_name, :type, :reference_node_id)'
        );
        $this->location_stmt = $db->prepare(
            'INSERT INTO context_node_locations'
            . ' (node_id, address, size, location_type, class_name, string_value, refcount, type_info, region)'
            . ' VALUES (:node_id, :address, :size, :location_type, :class_name, :string_value, :refcount, :type_info, :region)'
        );
        $this->attr_stmt = $db->prepare(
            'INSERT INTO context_node_attributes (node_id, key, value)'
            . ' VALUES (:node_id, :key, :value)'
        );
    }

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
    ): void {
        $this->node_stmt->execute([
            ':node_id' => $node_id,
            ':parent_node_id' => $parent_node_id,
            ':link_name' => $link_name,
            ':type' => $type,
            ':reference_node_id' => null,
        ]);

        foreach ($locations as $location) {
            if ($location instanceof MemoryLocation) {
                $short_class = (new \ReflectionClass($location))->getShortName();
                $this->location_stmt->execute([
                    ':node_id' => $node_id,
                    ':address' => $location->address,
                    ':size' => $location->size,
                    ':location_type' => $short_class,
                    ':class_name' => $location instanceof ZendObjectMemoryLocation
                        ? $location->class_name : null,
                    ':string_value' => $location instanceof ZendStringMemoryLocation
                        ? $location->value : null,
                    ':refcount' => $location instanceof RefcountedMemoryLocation
                        ? $location->refcount : null,
                    ':type_info' => $location instanceof RefcountedMemoryLocation
                        ? $location->type_info : null,
                    ':region' => $this->region_boundaries?->classifyRegion($location),
                ]);
            }
        }

        foreach ($attributes as $key => $value) {
            $this->attr_stmt->execute([
                ':node_id' => $node_id,
                ':key' => (string)$key,
                ':value' => is_scalar($value) ? (string)$value : json_encode($value),
            ]);
        }
    }

    #[\Override]
    public function allowsRelease(): bool
    {
        return true;
    }

    #[\Override]
    public function emitReference(
        int $reference_node_id,
        ?int $parent_node_id,
        string $link_name,
    ): void {
        $this->node_stmt->execute([
            ':node_id' => $reference_node_id,
            ':parent_node_id' => $parent_node_id,
            ':link_name' => $link_name,
            ':type' => null,
            ':reference_node_id' => $reference_node_id,
        ]);
    }
}
