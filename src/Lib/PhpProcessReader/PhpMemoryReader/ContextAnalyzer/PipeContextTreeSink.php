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

/**
 * A ContextTreeSink that serializes emit calls to a pipe/stream.
 *
 * Used by worker processes in the pipe-based parallel architecture:
 * workers traverse the graph and write flattened records to a pipe,
 * the parent process reads from the pipe and feeds PdoContextTreeSink.
 *
 * Wire format (all values written via serialize()):
 *   'N' => emitNode   (node_id, parent_node_id, link_name, type, locations[], attributes)
 *   'R' => emitReference (reference_node_id, parent_node_id, link_name)
 *   'E' => end of stream
 *
 * Locations are pre-flattened into tuples to avoid sending complex objects.
 */
final class PipeContextTreeSink implements ContextTreeSink
{
    /** @var array<class-string, string> */
    private array $short_name_cache = [];

    /**
     * @param resource $pipe  Writable stream (pipe fd)
     */
    public function __construct(
        private mixed $pipe,
        private ?RegionBoundaries $region_boundaries = null,
    ) {
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
        $flat_locations = [];
        foreach ($locations as $location) {
            $class = $location::class;
            $short_class = $this->short_name_cache[$class]
                ??= (new \ReflectionClass($class))->getShortName();
            $flat_locations[] = [
                $location->address,
                $location->size,
                $short_class,
                $location instanceof ZendObjectMemoryLocation ? $location->class_name : null,
                $location instanceof ZendStringMemoryLocation ? $location->value : null,
                $location instanceof RefcountedMemoryLocation ? $location->refcount : null,
                $location instanceof RefcountedMemoryLocation ? $location->type_info : null,
                $this->region_boundaries?->classifyRegion($location),
            ];
        }

        if (!is_array($attributes)) {
            $attributes = iterator_to_array($attributes);
        }

        $this->writeMessage(['N', $node_id, $parent_node_id, $link_name, $type, $flat_locations, $attributes]);
    }

    #[\Override]
    public function emitReference(
        int $reference_node_id,
        ?int $parent_node_id,
        string $link_name,
    ): void {
        $this->writeMessage(['R', $reference_node_id, $parent_node_id, $link_name]);
    }

    #[\Override]
    public function allowsRelease(): bool
    {
        return true;
    }

    public function sendEnd(): void
    {
        $this->writeMessage(['E']);
    }

    private function writeMessage(array $message): void
    {
        $data = serialize($message);
        $len = strlen($data);
        // Length-prefixed framing: 4-byte little-endian length + payload
        fwrite($this->pipe, pack('V', $len) . $data);
    }
}
