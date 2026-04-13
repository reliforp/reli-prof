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

use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Format;
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\StringDict;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\RefcountedMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\EdgeStrength;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionBoundaries;
use Reli\Lib\Process\MemoryLocation;

/**
 * ContextTreeSink that streams data to temp files for binary .rmem output.
 *
 * Nodes, edges, locations, and attributes are buffered in PHP strings
 * (configurable batch size) and flushed to separate temp files when the
 * buffer is full. Only the StringDict is kept in memory for the entire
 * collection (typically a few MB even for multi-GB captures). The temp
 * files are consumed by BinaryMemoryOutput::finalizeStreaming() to
 * assemble the final .rmem file.
 *
 * Row layouts (all little-endian):
 *
 *   Node (16 bytes):  [node_id:u32, canonical_id:u32, type_id:u32, class_id:u32]
 *   Edge (16 bytes):  [parent_node_id:u32, child_node_id:u32, link_name_id:u32,
 *                       is_tree:u8, strength:u8, _pad:u16]
 *   Location (48 bytes): [node_id:u32, location_type_id:u32, class_id:u32,
 *                          address:u64, size:u64, string_value_id:u32,
 *                          refcount:u32, type_info:u32, region_id:u32,
 *                          bin_overhead:u32]
 *   Attribute (12 bytes): [node_id:u32, key_id:u32, value_id:u32]
 */
final class BinaryContextTreeSink implements ContextTreeSink
{
    /**
     * Default batch size in rows. Each batch flushes ~3.2 MB for edges
     * (200k × 16 bytes), ~9.6 MB for locations (200k × 48 bytes).
     * Users can tune this via constructor to trade memory for I/O.
     */
    private const DEFAULT_BATCH_SIZE = 200000;

    /** @var array<class-string, string> */
    private array $short_name_cache = [];

    private StringDict $stringDict;

    // In-memory write buffers (flushed to temp files at batch boundary)
    private string $nodeBuf = '';
    private int $nodeBufRows = 0;
    private string $edgeBuf = '';
    private int $edgeBufRows = 0;
    private string $locationBuf = '';
    private int $locationBufRows = 0;
    private string $attrBuf = '';
    private int $attrBufRows = 0;

    /** @var resource */
    private $nodeFile;
    /** @var resource */
    private $edgeFile;
    /** @var resource */
    private $locationFile;
    /** @var resource */
    private $attrFile;

    private string $nodeTmpPath;
    private string $edgeTmpPath;
    private string $locationTmpPath;
    private string $attrTmpPath;

    private int $nodeCount = 0;
    private int $edgeCount = 0;
    private int $locationCount = 0;
    private int $attrCount = 0;

    /** @var array<int, int> node_id => row index in nodeFile (for class_id fixup) */
    private array $nodeRowIndices = [];

    /** Rows to accumulate before flushing each section to its temp file */
    private int $batchSize;

    /**
     * @param int $batch_size Rows per flush batch. Higher values trade
     *   memory for fewer syscalls. 200k (default) keeps per-section
     *   buffer peak at ~10 MB (locations × 48 bytes). Set lower on
     *   memory-constrained machines.
     */
    public function __construct(
        private ?RegionBoundaries $region_boundaries = null,
        int $batch_size = self::DEFAULT_BATCH_SIZE,
    ) {
        $this->batchSize = $batch_size;
        $this->stringDict = new StringDict();

        $this->nodeTmpPath = $this->createTmpFile('rmem_nodes_');
        $this->edgeTmpPath = $this->createTmpFile('rmem_edges_');
        $this->locationTmpPath = $this->createTmpFile('rmem_locs_');
        $this->attrTmpPath = $this->createTmpFile('rmem_attrs_');

        $this->nodeFile = fopen($this->nodeTmpPath, 'w+b') ?: throw new \RuntimeException('Cannot open node temp file');
        $this->edgeFile = fopen($this->edgeTmpPath, 'w+b') ?: throw new \RuntimeException('Cannot open edge temp file');
        $this->locationFile = fopen($this->locationTmpPath, 'w+b') ?: throw new \RuntimeException('Cannot open location temp file');
        $this->attrFile = fopen($this->attrTmpPath, 'w+b') ?: throw new \RuntimeException('Cannot open attr temp file');
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    public function setRegionBoundaries(RegionBoundaries $region_boundaries): void
    {
        $this->region_boundaries = $region_boundaries;
    }

    public function getStringDict(): StringDict
    {
        return $this->stringDict;
    }

    public function getNodeCount(): int
    {
        return $this->nodeCount;
    }

    public function getEdgeCount(): int
    {
        return $this->edgeCount;
    }

    public function getLocationCount(): int
    {
        return $this->locationCount;
    }

    public function getAttrCount(): int
    {
        return $this->attrCount;
    }

    public function getNodeTmpPath(): string
    {
        return $this->nodeTmpPath;
    }

    public function getEdgeTmpPath(): string
    {
        return $this->edgeTmpPath;
    }

    public function getLocationTmpPath(): string
    {
        return $this->locationTmpPath;
    }

    public function getAttrTmpPath(): string
    {
        return $this->attrTmpPath;
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
        EdgeStrength $edge_strength = EdgeStrength::Strong,
    ): void {
        // Deduplicate nodes (PdoContextTreeSink uses INSERT IGNORE)
        $is_new_node = !isset($this->nodeRowIndices[$node_id]);
        if ($is_new_node) {
            $type_id = $this->stringDict->intern($type);

            $this->nodeRowIndices[$node_id] = $this->nodeCount;

            // Node row: node_id:u32, canonical_id:u32(0=unset), type_id:u32, class_id:u32(NULL_STRING_ID=unset)
            $this->nodeBuf .= pack('VVVV',
                $node_id,
                0,
                $type_id,
                Format::NULL_STRING_ID,
            );
            $this->nodeCount++;
            $this->nodeBufRows++;
            if ($this->nodeBufRows >= $this->batchSize) {
                $this->flushNodes();
            }
        }

        // Buffer tree edge
        $this->bufferEdge($parent_node_id, $node_id, $link_name, 1, $edge_strength);

        // Buffer locations
        $first_class_id = null;
        foreach ($locations as $location) {
            $class = $location::class;
            $short_class = $this->short_name_cache[$class]
                ??= (new \ReflectionClass($class))->getShortName();

            $location_type_id = $this->stringDict->intern($short_class);

            $class_name_val = $location instanceof ZendObjectMemoryLocation
                ? $location->class_name : null;
            $class_id = $this->stringDict->intern($class_name_val);

            if ($first_class_id === null && $class_name_val !== null) {
                $first_class_id = $class_id;
            }

            $string_value_val = $location instanceof ZendStringMemoryLocation
                ? $location->value : null;
            $string_value_id = $this->stringDict->intern($string_value_val);

            $refcount = $location instanceof RefcountedMemoryLocation
                ? $location->refcount : 0;
            $type_info = $location instanceof RefcountedMemoryLocation
                ? $location->type_info : 0;

            $region = $this->region_boundaries?->classifyRegion($location);
            $region_id = $this->stringDict->intern($region);

            $bin_overhead = $this->region_boundaries?->computeBinOverhead($location) ?? 0;

            // Location row: 48 bytes (3V + 2P + 5V = 12 + 16 + 20)
            $this->locationBuf .= pack('VVVPPVVVVV',
                $node_id,           // 4
                $location_type_id,  // 4
                $class_id,          // 4
                $location->address, // 8
                $location->size,    // 8
                $string_value_id,   // 4
                $refcount,          // 4
                $type_info,         // 4
                $region_id,         // 4
                $bin_overhead,      // 4
            );
            $this->locationCount++;
            $this->locationBufRows++;
            if ($this->locationBufRows >= $this->batchSize) {
                $this->flushLocations();
            }
        }

        // Fix up node's class_id if we found one and the node was just created
        if ($first_class_id !== null && $is_new_node) {
            $row_index = $this->nodeRowIndices[$node_id];
            $file_byte_offset = $row_index * Format::NODE_ROW_SIZE;
            // How many rows have already been flushed to the file?
            $flushed_rows = $this->nodeCount - $this->nodeBufRows;
            $flushed_byte_end = $flushed_rows * Format::NODE_ROW_SIZE;

            if ($file_byte_offset < $flushed_byte_end) {
                // Node was already flushed to file — seek and patch
                fseek($this->nodeFile, $file_byte_offset + 12);
                fwrite($this->nodeFile, pack('V', $first_class_id));
                // Seek back to end for next append
                fseek($this->nodeFile, 0, SEEK_END);
            } else {
                // Node is still in the in-memory buffer — patch directly
                $buf_byte_offset = ($row_index - $flushed_rows) * Format::NODE_ROW_SIZE + 12;
                $packed = pack('V', $first_class_id);
                $this->nodeBuf[$buf_byte_offset] = $packed[0];
                $this->nodeBuf[$buf_byte_offset + 1] = $packed[1];
                $this->nodeBuf[$buf_byte_offset + 2] = $packed[2];
                $this->nodeBuf[$buf_byte_offset + 3] = $packed[3];
            }
        }

        // Buffer attributes
        /** @psalm-suppress MixedAssignment */
        foreach ($attributes as $key => $value) {
            $key_id = $this->stringDict->intern($key);
            $string_value = is_scalar($value) ? (string)$value : json_encode($value);
            assert(is_string($string_value));
            $value_id = $this->stringDict->intern($string_value);

            $this->attrBuf .= pack('VVV',
                $node_id,
                $key_id,
                $value_id,
            );
            $this->attrCount++;
            $this->attrBufRows++;
            if ($this->attrBufRows >= $this->batchSize) {
                $this->flushAttrs();
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
        EdgeStrength $edge_strength = EdgeStrength::Strong,
    ): void {
        $this->bufferEdge($parent_node_id, $reference_node_id, $link_name, 0, $edge_strength);
    }

    public function flush(): void
    {
        if ($this->nodeBufRows > 0) {
            $this->flushNodes();
        }
        if ($this->edgeBufRows > 0) {
            $this->flushEdges();
        }
        if ($this->locationBufRows > 0) {
            $this->flushLocations();
        }
        if ($this->attrBufRows > 0) {
            $this->flushAttrs();
        }
    }

    /**
     * Close temp file handles. Called by BinaryMemoryOutput after
     * it has consumed the temp files.
     */
    public function closeTempFiles(): void
    {
        if (is_resource($this->nodeFile)) {
            fclose($this->nodeFile);
        }
        if (is_resource($this->edgeFile)) {
            fclose($this->edgeFile);
        }
        if (is_resource($this->locationFile)) {
            fclose($this->locationFile);
        }
        if (is_resource($this->attrFile)) {
            fclose($this->attrFile);
        }
    }

    /**
     * Delete temp files. Called after the .rmem is fully written.
     */
    public function cleanup(): void
    {
        $this->closeTempFiles();
        foreach ([$this->nodeTmpPath, $this->edgeTmpPath, $this->locationTmpPath, $this->attrTmpPath] as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }

    private function bufferEdge(
        ?int $parent_node_id,
        int $child_node_id,
        string $link_name,
        int $is_tree,
        EdgeStrength $edge_strength,
    ): void {
        $link_name_id = $this->stringDict->intern($link_name);

        $strength_byte = match ($edge_strength) {
            EdgeStrength::Strong => 0,
            EdgeStrength::Weak => 1,
            EdgeStrength::Structural => 2,
        };

        $this->edgeBuf .= pack('VVVCCv',
            $parent_node_id ?? 0xFFFFFFFF,
            $child_node_id,
            $link_name_id,
            $is_tree,
            $strength_byte,
            0,
        );
        $this->edgeCount++;
        $this->edgeBufRows++;
        if ($this->edgeBufRows >= $this->batchSize) {
            $this->flushEdges();
        }
    }

    private function flushNodes(): void
    {
        fwrite($this->nodeFile, $this->nodeBuf);
        $this->nodeBuf = '';
        $this->nodeBufRows = 0;
    }

    private function flushEdges(): void
    {
        fwrite($this->edgeFile, $this->edgeBuf);
        $this->edgeBuf = '';
        $this->edgeBufRows = 0;
    }

    private function flushLocations(): void
    {
        fwrite($this->locationFile, $this->locationBuf);
        $this->locationBuf = '';
        $this->locationBufRows = 0;
    }

    private function flushAttrs(): void
    {
        fwrite($this->attrFile, $this->attrBuf);
        $this->attrBuf = '';
        $this->attrBufRows = 0;
    }

    private function createTmpFile(string $prefix): string
    {
        $base = tempnam(sys_get_temp_dir(), $prefix);
        if ($base === false) {
            throw new \RuntimeException('Failed to create temporary file');
        }
        return $base;
    }
}
