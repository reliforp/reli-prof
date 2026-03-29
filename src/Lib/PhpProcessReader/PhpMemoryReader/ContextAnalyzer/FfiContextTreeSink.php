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
 * A ContextTreeSink that packs node data into FFI-allocated (libc malloc)
 * buffers and sends the raw address via an ext-parallel Channel.
 *
 * The reader thread reconstructs the data by casting the address back to
 * a pointer and reading the binary payload — zero PHP serialize overhead.
 *
 * Buffer format (length-prefixed binary):
 *   [4 bytes: total_len] [1 byte: type]
 *   'N': [8: node_id] [8: parent_node_id] [str: link_name] [str: type]
 *        [4: num_locs] { [8: addr] [8: size] [str: loc_type] [str: class_name]
 *                         [str: string_value] [8: refcount] [8: type_info]
 *                         [str: region] } ...
 *        [4: num_attrs] { [str: key] [str: value] } ...
 *   'R': [8: ref_node_id] [8: parent_node_id] [str: link_name]
 *   'E': (no payload)
 *
 * Strings are encoded as [4 bytes: len] [len bytes data].
 * Nullable int is encoded as -1 for null.
 */
final class FfiContextTreeSink implements ContextTreeSink
{
    /** @var \FFI */
    private \FFI $libc;
    /** @var \FFI */
    private \FFI $helper_ffi;

    /** @var array<class-string, string> */
    private array $short_name_cache = [];

    /**
     * @param \parallel\Channel $channel
     */
    public function __construct(
        private object $channel,
        private ?RegionBoundaries $region_boundaries = null,
    ) {
        $this->libc = \FFI::cdef('void *malloc(size_t); void free(void*);', 'libc.so.6');
        $this->helper_ffi = \FFI::cdef('struct ph { void *ptr; };');
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
        // Build the binary payload in a PHP string first, then copy to FFI.
        $payload = 'N';
        $payload .= pack('P', $node_id);
        $payload .= pack('P', $parent_node_id ?? -1);
        $payload .= self::packStr($link_name);
        $payload .= self::packStr($type);

        // Locations
        $loc_data = '';
        $loc_count = 0;
        foreach ($locations as $location) {
            $class = $location::class;
            $short_class = $this->short_name_cache[$class]
                ??= (new \ReflectionClass($class))->getShortName();
            $loc_data .= pack('P', $location->address);
            $loc_data .= pack('P', $location->size);
            $loc_data .= self::packStr($short_class);
            $loc_data .= self::packStr(
                $location instanceof ZendObjectMemoryLocation ? $location->class_name : ''
            );
            $loc_data .= self::packStr(
                $location instanceof ZendStringMemoryLocation ? ($location->value ?? '') : ''
            );
            $loc_data .= pack('P', $location instanceof RefcountedMemoryLocation ? $location->refcount : -1);
            $loc_data .= pack('P', $location instanceof RefcountedMemoryLocation ? $location->type_info : -1);
            $loc_data .= self::packStr($this->region_boundaries?->classifyRegion($location) ?? '');
            $loc_count++;
        }
        $payload .= pack('V', $loc_count) . $loc_data;

        // Attributes
        if (!is_array($attributes)) {
            $attributes = iterator_to_array($attributes);
        }
        $payload .= pack('V', count($attributes));
        /** @psalm-suppress MixedAssignment */
        foreach ($attributes as $key => $value) {
            $string_value = is_scalar($value) ? (string)$value : (string)json_encode($value);
            $payload .= self::packStr((string)$key);
            $payload .= self::packStr($string_value);
        }

        $this->sendBuffer($payload);
    }

    #[\Override]
    public function emitReference(
        int $reference_node_id,
        ?int $parent_node_id,
        string $link_name,
    ): void {
        $payload = 'R';
        $payload .= pack('P', $reference_node_id);
        $payload .= pack('P', $parent_node_id ?? -1);
        $payload .= self::packStr($link_name);

        $this->sendBuffer($payload);
    }

    #[\Override]
    public function allowsRelease(): bool
    {
        return true;
    }

    public function sendEnd(): void
    {
        $this->sendBuffer('E');
    }

    private static function packStr(string $s): string
    {
        return pack('V', strlen($s)) . $s;
    }

    private function sendBuffer(string $payload): void
    {
        $total_len = strlen($payload);
        $buf = $this->libc->malloc($total_len + 4);
        $overlay_type = $this->helper_ffi->type("char[" . ($total_len + 4) . "]");
        $overlay = $this->helper_ffi->cast($overlay_type, $buf);
        \FFI::memcpy($overlay, pack('V', $total_len) . $payload, $total_len + 4);

        // Get raw address
        $h = $this->helper_ffi->new('struct ph');
        $h->ptr = $buf;
        $addr = unpack('P', \FFI::string(\FFI::addr($h), 8))[1];

        $this->channel->send($addr);
    }

    /**
     * Read a message from an FFI buffer address and replay it into a PdoContextTreeSink.
     *
     * Called by the writer thread.
     *
     * @return bool  true if message was processed, false if end sentinel
     */
    /** @var \FFI|null */
    private static ?\FFI $reader_ffi = null;

    public static function readAndReplay(int $addr, PdoContextTreeSink $sink): bool
    {
        $helper_ffi = self::$reader_ffi ??= \FFI::cdef('struct ph { void *ptr; }; void free(void*);', 'libc.so.6');

        // Reconstruct pointer
        $h = $helper_ffi->new('struct ph');
        \FFI::memcpy(\FFI::addr($h), pack('P', $addr), 8);
        $ptr = $helper_ffi->cast('char*', $h->ptr);

        // Read total length (first 4 bytes)
        $len_raw = \FFI::string($ptr, 4);
        $total_len = unpack('V', $len_raw)[1];

        // Read entire payload
        $full_type = $helper_ffi->type("char[" . ($total_len + 4) . "]");
        $full = $helper_ffi->cast($full_type, $h->ptr);
        $raw = \FFI::string($full, $total_len + 4);
        $payload = substr($raw, 4);

        // Free the buffer
        $helper_ffi->free($h->ptr);

        $type = $payload[0];

        if ($type === 'E') {
            return false;
        }

        $offset = 1;

        if ($type === 'N') {
            $node_id = unpack('P', $payload, $offset)[1];
            $offset += 8;
            $parent_raw = unpack('P', $payload, $offset)[1];
            $parent_node_id = $parent_raw === -1 ? null : $parent_raw;
            $offset += 8;

            [$link_name, $offset] = self::unpackStr($payload, $offset);
            [$type_name, $offset] = self::unpackStr($payload, $offset);

            $num_locs = unpack('V', $payload, $offset)[1];
            $offset += 4;

            $flat_locations = [];
            for ($i = 0; $i < $num_locs; $i++) {
                $loc_address = unpack('P', $payload, $offset)[1];
                $offset += 8;
                $loc_size = unpack('P', $payload, $offset)[1];
                $offset += 8;
                [$loc_type, $offset] = self::unpackStr($payload, $offset);
                [$class_name, $offset] = self::unpackStr($payload, $offset);
                [$string_value, $offset] = self::unpackStr($payload, $offset);
                $refcount_raw = unpack('P', $payload, $offset)[1];
                $offset += 8;
                $type_info_raw = unpack('P', $payload, $offset)[1];
                $offset += 8;
                [$region, $offset] = self::unpackStr($payload, $offset);

                $flat_locations[] = [
                    $loc_address,
                    $loc_size,
                    $loc_type,
                    $class_name === '' ? null : $class_name,
                    $string_value === '' ? null : $string_value,
                    $refcount_raw === -1 ? null : $refcount_raw,
                    $type_info_raw === -1 ? null : $type_info_raw,
                    $region === '' ? null : $region,
                ];
            }

            $num_attrs = unpack('V', $payload, $offset)[1];
            $offset += 4;
            $attributes = [];
            for ($i = 0; $i < $num_attrs; $i++) {
                [$key, $offset] = self::unpackStr($payload, $offset);
                [$value, $offset] = self::unpackStr($payload, $offset);
                $attributes[$key] = $value;
            }

            $sink->emitNodeFlat($node_id, $parent_node_id, $link_name, $type_name, $flat_locations, $attributes);
        } elseif ($type === 'R') {
            $ref_node_id = unpack('P', $payload, $offset)[1];
            $offset += 8;
            $parent_raw = unpack('P', $payload, $offset)[1];
            $parent_node_id = $parent_raw === -1 ? null : $parent_raw;
            $offset += 8;
            [$link_name, $offset] = self::unpackStr($payload, $offset);
            $sink->emitReference($ref_node_id, $parent_node_id, $link_name);
        }

        return true;
    }

    /**
     * @return array{string, int}  [value, new_offset]
     */
    private static function unpackStr(string $data, int $offset): array
    {
        $len = unpack('V', $data, $offset)[1];
        $offset += 4;
        $str = substr($data, $offset, $len);
        return [$str, $offset + $len];
    }
}
