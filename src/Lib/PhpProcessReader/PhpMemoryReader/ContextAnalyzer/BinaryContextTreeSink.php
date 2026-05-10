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

use Reli\Inspector\Output\MemoryOutput\BinaryFormat\DiskBackedStringDict;
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Format;
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
 * buffer is full. String data is stored in a DiskBackedStringDict that
 * keeps only a hash index in memory and writes string bodies to disk;
 * an LRU cache (configurable size) keeps frequently accessed strings
 * for dedup lookups. For ZendString values, the pre-computed hash from
 * zend_string.h is used when available (non-zero); other strings use
 * crc32 for hashing.
 *
 * Node deduplication is NOT performed at write time. The ContextAnalyzer
 * guarantees each context is emitted exactly once via getMemoNodeId /
 * setMemoNodeId, so duplicate emitNode calls are not expected. The
 * substrate loader handles any edge cases at read time.
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

    private DiskBackedStringDict $stringDict;

    // In-memory write buffers (flushed to temp files at batch boundary)
    private string $nodeBuf = '';
    private int $nodeBufRows = 0;
    private string $edgeBuf = '';
    private int $edgeBufRows = 0;
    private string $locationBuf = '';
    private int $locationBufRows = 0;
    private string $attrBuf = '';
    private int $attrBufRows = 0;

    /** @var resource|null */
    private $nodeFile;
    /** @var resource|null */
    private $edgeFile;
    /** @var resource|null */
    private $locationFile;
    /** @var resource|null */
    private $attrFile;

    private string $nodeTmpPath;
    private string $edgeTmpPath;
    private string $locationTmpPath;
    private string $attrTmpPath;

    private int $nodeCount = 0;
    private int $edgeCount = 0;
    private int $locationCount = 0;
    private int $attrCount = 0;

    // Per-node size/class accumulators for on-disk sections.
    // FFI int64/int32 arrays, grown as needed.
    /** @var \FFI\CArray<int>|null */
    private ?\FFI\CData $perNodeSizes = null;
    /** @var \FFI\CArray<int>|null */
    private ?\FFI\CData $perNodeClasses = null;
    private int $perNodeCapacity = 0;
    private int $maxNodeId = -1;

    // Canonical address grouping: address → min node_id seen so far.
    // Tracks whether multiple distinct node_ids share an address.
    /** @var array<int, int> address → min_node_id */
    private array $addressMinNode = [];
    /** @var array<int, true> addresses with more than one distinct node_id */
    private array $addressMultiple = [];

    /** Rows to accumulate before flushing each section to its temp file */
    private int $batchSize;

    /**
     * @param int $batch_size Rows per flush batch. Higher values trade
     *   memory for fewer syscalls. 200k (default) keeps per-section
     *   buffer peak at ~10 MB (locations × 48 bytes). Set lower on
     *   memory-constrained machines.
     * @param int $dict_cache_bytes Max bytes of string data to keep in
     *   the DiskBackedStringDict's LRU cache. Default 64 MiB.
     */
    public function __construct(
        private ?RegionBoundaries $region_boundaries = null,
        int $batch_size = self::DEFAULT_BATCH_SIZE,
        int $dict_cache_bytes = 64 * 1024 * 1024,
    ) {
        $this->batchSize = $batch_size;
        $this->stringDict = new DiskBackedStringDict($dict_cache_bytes);

        $this->nodeTmpPath = $this->createTmpFile('rmem_nodes_');
        $this->edgeTmpPath = $this->createTmpFile('rmem_edges_');
        $this->locationTmpPath = $this->createTmpFile('rmem_locs_');
        $this->attrTmpPath = $this->createTmpFile('rmem_attrs_');

        $this->nodeFile = fopen($this->nodeTmpPath, 'w+b')
            ?: throw new \RuntimeException('Cannot open node temp file');
        $this->edgeFile = fopen($this->edgeTmpPath, 'w+b')
            ?: throw new \RuntimeException('Cannot open edge temp file');
        $this->locationFile = fopen($this->locationTmpPath, 'w+b')
            ?: throw new \RuntimeException('Cannot open location temp file');
        $this->attrFile = fopen($this->attrTmpPath, 'w+b')
            ?: throw new \RuntimeException('Cannot open attr temp file');
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    public function setRegionBoundaries(RegionBoundaries $region_boundaries): void
    {
        $this->region_boundaries = $region_boundaries;
    }

    public function getStringDict(): DiskBackedStringDict
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
        // No dedup: ContextAnalyzer guarantees each context is emitted
        // once via getMemoNodeId/setMemoNodeId. Substrate loader handles
        // any edge cases at read time.
        $type_id = $this->stringDict->intern($type);

        // Node row: node_id:u32, canonical_id:u32(0=unset), type_id:u32, class_id:u32(NULL_STRING_ID)
        // class_id is left as NULL_STRING_ID — the substrate loader
        // derives class from the locations section.
        $this->nodeBuf .= pack(
            'VVVV',
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

        // Buffer tree edge
        $this->bufferEdge($parent_node_id, $node_id, $link_name, 1, $edge_strength);

        // Buffer locations
        foreach ($locations as $location) {
            $class = $location::class;
            $short_class = $this->short_name_cache[$class]
                ??= (new \ReflectionClass($class))->getShortName();

            $location_type_id = $this->stringDict->intern($short_class);

            $class_name_val = $location instanceof ZendObjectMemoryLocation
                ? $location->class_name : null;
            $class_id = $this->stringDict->intern($class_name_val);

            $string_value_val = $location instanceof ZendStringMemoryLocation
                ? $location->value : null;
            $string_hash = $location instanceof ZendStringMemoryLocation
                ? $location->hash : 0;
            $string_value_id = $this->stringDict->intern($string_value_val, $string_hash);

            $refcount = $location instanceof RefcountedMemoryLocation
                ? $location->refcount : 0;
            $type_info = $location instanceof RefcountedMemoryLocation
                ? $location->type_info : 0;

            $region = $this->region_boundaries?->classifyRegion($location);
            $region_id = $this->stringDict->intern($region);

            $bin_overhead = $this->region_boundaries?->computeBinOverhead($location) ?? 0;

            // Location row: 48 bytes (3V + 2P + 5V = 12 + 16 + 20)
            $this->locationBuf .= pack(
                'VVVPPVVVVV',
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

            // Accumulate per-node sizes and classes for on-disk sections.
            // perNodeSizes / perNodeClasses are typed \FFI\CArray<int> via
            // their property docblocks, so the array reads return int with
            // no per-iteration Cast::toInt() dispatch.
            $this->ensurePerNodeCapacity($node_id);
            $this->perNodeSizes[$node_id] = $this->perNodeSizes[$node_id] + $location->size;
            if (
                $class_id !== Format::NULL_STRING_ID
                && $this->perNodeClasses[$node_id] === Format::NULL_STRING_ID
            ) {
                $this->perNodeClasses[$node_id] = $class_id;
            }

            // Track address → min node_id for canonical grouping
            if ($location->address !== 0) {
                $addr = $location->address;
                if (!isset($this->addressMinNode[$addr])) {
                    $this->addressMinNode[$addr] = $node_id;
                } else {
                    if ($this->addressMinNode[$addr] !== $node_id) {
                        $this->addressMultiple[$addr] = true;
                        if ($node_id < $this->addressMinNode[$addr]) {
                            $this->addressMinNode[$addr] = $node_id;
                        }
                    }
                }
            }
        }

        // Buffer attributes
        /** @psalm-suppress MixedAssignment */
        foreach ($attributes as $key => $value) {
            $key_id = $this->stringDict->intern($key);
            $string_value = is_scalar($value) ? (string)$value : json_encode($value);
            assert(is_string($string_value));
            $value_id = $this->stringDict->intern($string_value);

            $this->attrBuf .= pack(
                'VVV',
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
        $node_file = $this->nodeFile;
        if (is_resource($node_file)) {
            fclose($node_file);
            $this->nodeFile = null;
        }
        $edge_file = $this->edgeFile;
        if (is_resource($edge_file)) {
            fclose($edge_file);
            $this->edgeFile = null;
        }
        $location_file = $this->locationFile;
        if (is_resource($location_file)) {
            fclose($location_file);
            $this->locationFile = null;
        }
        $attr_file = $this->attrFile;
        if (is_resource($attr_file)) {
            fclose($attr_file);
            $this->attrFile = null;
        }
    }

    /**
     * Compute raw per-region size sums and total bin_overhead by scanning
     * the binary location temp file.
     *
     * This is the binary streaming counterpart of
     * RegionsSummary::queryRegionSums(), but it intentionally reports raw
     * location-row sums: unlike the SQL variant it does not GROUP BY
     * address / MAX(size) and does not skip overlapping locations. The
     * binary report path has historically read these raw sums, and the
     * existing tests assume that shape.
     *
     * @return array{sums: array<string, int>, overhead: int}
     */
    public function computeRegionSumsAndOverhead(): array
    {
        $this->flush();

        $fh = fopen($this->locationTmpPath, 'rb');
        if ($fh === false) {
            return ['sums' => [], 'overhead' => 0];
        }
        $row_size = Format::LOCATION_ROW_SIZE;

        /** @var array<string, int> $sums */
        $sums = [];
        $total_overhead = 0;
        while (true) {
            $row = fread($fh, $row_size);
            if ($row === false || strlen($row) < $row_size) {
                break;
            }
            /** @var array{1: int} $size_row */
            $size_row = unpack('P', $row, 20);
            /** @var array{1: int} $region_id_row */
            $region_id_row = unpack('V', $row, 40);
            /** @var array{1: int} $overhead_row */
            $overhead_row = unpack('V', $row, 44);
            $size = $size_row[1];
            $region_id = $region_id_row[1];
            $bin_overhead = $overhead_row[1];
            $region = $this->stringDict->lookup($region_id);
            if ($region === null) {
                continue;
            }
            $sums[$region] = ($sums[$region] ?? 0) + $size;
            $total_overhead += $bin_overhead;
        }
        fclose($fh);
        return ['sums' => $sums, 'overhead' => $total_overhead];
    }

    /**
     * Delete temp files. Called after the .rmem is fully written.
     */
    public function cleanup(): void
    {
        $this->closeTempFiles();
        $this->stringDict->cleanup();
        foreach ([$this->nodeTmpPath, $this->edgeTmpPath, $this->locationTmpPath, $this->attrTmpPath] as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * @psalm-assert !null $this->perNodeSizes
     * @psalm-assert !null $this->perNodeClasses
     */
    private function ensurePerNodeCapacity(int $node_id): void
    {
        if ($node_id > $this->maxNodeId) {
            $this->maxNodeId = $node_id;
        }
        if ($node_id < $this->perNodeCapacity) {
            return;
        }
        $new_cap = max(4096, $this->perNodeCapacity);
        while ($new_cap <= $node_id) {
            $new_cap *= 2;
        }
        $new_sizes = \Reli\Lib\FFI\FFIHelper::newInt64Array($new_cap);
        // Class slots hold string-dict IDs (unsigned 32-bit, with
        // Format::NULL_STRING_ID = 0xFFFFFFFF reserved for "unset").
        // `uint32_t` is the right type for that representation: a
        // signed `int32_t` reads NULL_STRING_ID back as `-1`, which
        // makes `=== Format::NULL_STRING_ID` (the natural guard) never
        // match and silently leaves every per-node class id as NULL on
        // disk — see `docs/internals/memory-report-t2-3-investigation.md`
        // for the failure trail this caused on the binary report path.
        $new_classes = \Reli\Lib\FFI\FFIHelper::newUint32Array($new_cap);
        // Initialize new class slots to NULL_STRING_ID
        for ($i = 0; $i < $new_cap; $i++) {
            $new_classes[$i] = Format::NULL_STRING_ID;
        }
        // Copy old data
        if (
            $this->perNodeSizes !== null
            && $this->perNodeClasses !== null
            && $this->perNodeCapacity > 0
        ) {
            \FFI::memcpy($new_sizes, $this->perNodeSizes, $this->perNodeCapacity * 8);
            \FFI::memcpy($new_classes, $this->perNodeClasses, $this->perNodeCapacity * 4);
        }
        $this->perNodeSizes = $new_sizes;
        $this->perNodeClasses = $new_classes;
        $this->perNodeCapacity = $new_cap;
    }

    /**
     * Get per-node sizes as FFI int64 array. Used by BinaryMemoryOutput
     * to write the node_sizes section.
     */
    public function getPerNodeSizes(): ?\FFI\CData
    {
        return $this->perNodeSizes;
    }

    /**
     * Get per-node class IDs as FFI uint32 array (string dict IDs).
     */
    public function getPerNodeClasses(): ?\FFI\CData
    {
        return $this->perNodeClasses;
    }

    public function getMaxNodeId(): int
    {
        return $this->maxNodeId;
    }

    /**
     * Build canonical map: for each node_id, the canonical node_id
     * (min among all nodes sharing the same address).
     * Returns FFI int32[maxNodeId+1], -1 for self-canonical.
     *
     * Uses a two-pass approach: addressMinNode (built during emit)
     * gives the min node_id per address. Then re-scans the locations
     * temp file to find all node_ids at multi-node addresses and
     * assigns them the canonical.
     */
    public function buildCanonicalMap(): ?\FFI\CData
    {
        if ($this->maxNodeId < 0) {
            return null;
        }
        if ($this->addressMultiple === []) {
            return null; // no shared addresses
        }

        $slots = $this->maxNodeId + 1;
        $map = \Reli\Lib\FFI\FFIHelper::newInt32Array($slots);
        for ($i = 0; $i < $slots; $i++) {
            $map[$i] = -1;
        }

        // Re-scan locations temp file to find node_ids at shared addresses
        $locPath = $this->locationTmpPath;
        $fp = fopen($locPath, 'rb');
        if ($fp === false) {
            return $map;
        }

        $row_size = Format::LOCATION_ROW_SIZE; // 48 bytes
        $chunk_rows = 10000;
        while (!feof($fp)) {
            $data = fread($fp, $chunk_rows * $row_size);
            if ($data === false || $data === '') {
                break;
            }
            $rows = (int)(strlen($data) / $row_size);
            for ($i = 0; $i < $rows; $i++) {
                $off = $i * $row_size;
                /** @var array{1: int} */
                $nid_u = unpack('V', $data, $off);
                $node_id = $nid_u[1];
                /** @var array{1: int} */
                $addr_u = unpack('P', $data, $off + 12);
                $address = $addr_u[1];

                if ($address !== 0 && isset($this->addressMultiple[$address])) {
                    $canon = $this->addressMinNode[$address];
                    $map[$node_id] = $canon;
                }
            }
        }
        fclose($fp);

        // Free the tracking arrays
        $this->addressMinNode = [];
        $this->addressMultiple = [];

        return $map;
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

        $this->edgeBuf .= pack(
            'VVVCCv',
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
        fwrite($this->getNodeFile(), $this->nodeBuf);
        $this->nodeBuf = '';
        $this->nodeBufRows = 0;
    }

    private function flushEdges(): void
    {
        fwrite($this->getEdgeFile(), $this->edgeBuf);
        $this->edgeBuf = '';
        $this->edgeBufRows = 0;
    }

    private function flushLocations(): void
    {
        fwrite($this->getLocationFile(), $this->locationBuf);
        $this->locationBuf = '';
        $this->locationBufRows = 0;
    }

    private function flushAttrs(): void
    {
        fwrite($this->getAttrFile(), $this->attrBuf);
        $this->attrBuf = '';
        $this->attrBufRows = 0;
    }

    /**
     * @return resource
     */
    private function getNodeFile()
    {
        if (!is_resource($this->nodeFile)) {
            throw new \RuntimeException('Node temp file is already closed');
        }
        return $this->nodeFile;
    }

    /**
     * @return resource
     */
    private function getEdgeFile()
    {
        if (!is_resource($this->edgeFile)) {
            throw new \RuntimeException('Edge temp file is already closed');
        }
        return $this->edgeFile;
    }

    /**
     * @return resource
     */
    private function getLocationFile()
    {
        if (!is_resource($this->locationFile)) {
            throw new \RuntimeException('Location temp file is already closed');
        }
        return $this->locationFile;
    }

    /**
     * @return resource
     */
    private function getAttrFile()
    {
        if (!is_resource($this->attrFile)) {
            throw new \RuntimeException('Attribute temp file is already closed');
        }
        return $this->attrFile;
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
