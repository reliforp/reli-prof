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

use Reli\Inspector\Output\MemoryOutput\PdoDriver\PdoDriverInterface;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\RefcountedMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionBoundaries;
use Reli\Lib\Process\MemoryLocation;

final class PdoContextTreeSink implements ContextTreeSink
{
    private const LOCATION_BATCH_SIZE = 200;
    private const ATTR_BATCH_SIZE = 200;
    private const EDGE_BATCH_SIZE = 200;
    private const LOCATION_COLUMNS = 9;
    private const ATTR_COLUMNS = 3;
    private const EDGE_COLUMNS = 4;

    private \PDOStatement $node_stmt;

    /** @var array<class-string, string> */
    private array $short_name_cache = [];

    /** @var list<mixed> */
    private array $location_buffer = [];
    private int $location_row_count = 0;

    /** @var list<mixed> */
    private array $attr_buffer = [];
    private int $attr_row_count = 0;

    /** @var list<mixed> */
    private array $edge_buffer = [];
    private int $edge_row_count = 0;

    /** @var array<int, \PDOStatement> */
    private array $location_batch_stmts = [];
    /** @var array<int, \PDOStatement> */
    private array $attr_batch_stmts = [];
    /** @var array<int, \PDOStatement> */
    private array $edge_batch_stmts = [];

    public function __construct(
        private \PDO $db,
        private PdoDriverInterface $driver,
        private ?RegionBoundaries $region_boundaries = null,
    ) {
        $this->node_stmt = $db->prepare(
            $driver->insertIgnoreSql('context_nodes', 'node_id, type', '?, ?')
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
        $this->node_stmt->execute([$node_id, $type]);
        $this->bufferEdge($parent_node_id, $node_id, $link_name, 1);

        foreach ($locations as $location) {
            $class = $location::class;
            $short_class = $this->short_name_cache[$class]
                ??= (new \ReflectionClass($class))->getShortName();
            $this->location_buffer[] = $node_id;
            $this->location_buffer[] = $location->address;
            $this->location_buffer[] = $location->size;
            $this->location_buffer[] = $short_class;
            $this->location_buffer[] = $location instanceof ZendObjectMemoryLocation
                ? $location->class_name : null;
            $this->location_buffer[] = $location instanceof ZendStringMemoryLocation
                ? $location->value : null;
            $this->location_buffer[] = $location instanceof RefcountedMemoryLocation
                ? $location->refcount : null;
            $this->location_buffer[] = $location instanceof RefcountedMemoryLocation
                ? $location->type_info : null;
            $this->location_buffer[] = $this->region_boundaries?->classifyRegion($location);
            $this->location_row_count++;

            if ($this->location_row_count >= self::LOCATION_BATCH_SIZE) {
                $this->flushLocations();
            }
        }

        foreach ($attributes as $key => $value) {
            $this->attr_buffer[] = $node_id;
            $this->attr_buffer[] = $key;
            $string_value = is_scalar($value) ? (string)$value : json_encode($value);
            assert(is_string($string_value));
            $this->attr_buffer[] = $string_value;
            $this->attr_row_count++;

            if ($this->attr_row_count >= self::ATTR_BATCH_SIZE) {
                $this->flushAttributes();
            }
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
        $this->bufferEdge($parent_node_id, $reference_node_id, $link_name, 0);
    }

    public function flush(): void
    {
        if ($this->location_row_count > 0) {
            $this->flushLocations();
        }
        if ($this->attr_row_count > 0) {
            $this->flushAttributes();
        }
        if ($this->edge_row_count > 0) {
            $this->flushEdges();
        }
    }

    private function flushLocations(): void
    {
        $count = $this->location_row_count;
        $stmt = $this->getLocationBatchStmt($count);
        $stmt->execute($this->location_buffer);
        $this->location_buffer = [];
        $this->location_row_count = 0;
    }

    private function flushAttributes(): void
    {
        $count = $this->attr_row_count;
        $stmt = $this->getAttrBatchStmt($count);
        $stmt->execute($this->attr_buffer);
        $this->attr_buffer = [];
        $this->attr_row_count = 0;
    }

    private function getLocationBatchStmt(int $row_count): \PDOStatement
    {
        return $this->location_batch_stmts[$row_count]
            ??= $this->db->prepare(
                'INSERT INTO context_node_locations'
                . ' (node_id, address, size, location_type, class_name, string_value, refcount, type_info, region)'
                . ' VALUES ' . implode(',', array_fill(0, $row_count, '(?,?,?,?,?,?,?,?,?)'))
            );
    }

    private function getAttrBatchStmt(int $row_count): \PDOStatement
    {
        $qi = fn (string $id): string => $this->driver->quoteIdentifier($id);
        return $this->attr_batch_stmts[$row_count]
            ??= $this->db->prepare(
                "INSERT INTO context_node_attributes (node_id, {$qi('key')}, {$qi('value')})"
                . ' VALUES ' . implode(',', array_fill(0, $row_count, '(?,?,?)'))
            );
    }

    private function bufferEdge(?int $parent_node_id, int $child_node_id, string $link_name, int $is_tree): void
    {
        $this->edge_buffer[] = $parent_node_id;
        $this->edge_buffer[] = $child_node_id;
        $this->edge_buffer[] = $link_name;
        $this->edge_buffer[] = $is_tree;
        $this->edge_row_count++;

        if ($this->edge_row_count >= self::EDGE_BATCH_SIZE) {
            $this->flushEdges();
        }
    }

    private function flushEdges(): void
    {
        $count = $this->edge_row_count;
        $stmt = $this->getEdgeBatchStmt($count);
        $stmt->execute($this->edge_buffer);
        $this->edge_buffer = [];
        $this->edge_row_count = 0;
    }

    private function getEdgeBatchStmt(int $row_count): \PDOStatement
    {
        return $this->edge_batch_stmts[$row_count]
            ??= $this->db->prepare(
                'INSERT INTO context_edges (parent_node_id, child_node_id, link_name, is_tree)'
                . ' VALUES ' . implode(',', array_fill(0, $row_count, '(?,?,?,?)'))
            );
    }
}
