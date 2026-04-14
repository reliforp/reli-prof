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

namespace Reli\Inspector\Output\MemoryOutput\BinaryFormat;

use FFI;
use FFI\CData;
use Reli\Lib\FFI\FFIHelper;
use Reli\Lib\File\LibcFileReader;

/**
 * Reader for the .rmem binary format.
 *
 * Uses mmap when FFI is available (Stage 2): the file stays on disk,
 * pages are loaded on demand by the kernel, and substrate loaders can
 * FFI::cast directly into the mapped region without any PHP-level copy.
 *
 * Falls back to file_get_contents when FFI/mmap is unavailable.
 *
 * @psalm-suppress PossiblyInvalidArrayAccess
 * @psalm-suppress MixedAssignment
 * @psalm-suppress PossiblyNullOperand
 * @psalm-suppress InvalidOperand
 * @psalm-suppress MixedOperand
 * @psalm-suppress InvalidArgument
 * @psalm-suppress MixedArgument
 * @psalm-suppress PossiblyNullArgument
 */
final class Reader
{
    /** @var array<string, array{offset: int, length: int, element_count: int}> */
    private array $toc = [];

    private StringDict $stringDict;

    // mmap-backed storage (preferred)
    private ?CData $mmapPtr = null;
    private int $mmapLength = 0;

    // castSection cache: "section:type" → CData
    /** @var array<string, CData> */
    private array $castCache = [];

    // Fallback: entire file in a PHP string
    private ?string $fileData = null;

    /** FFI instance for struct casts (lazy-initialized) */
    private static ?FFI $structFfi = null;

    private function __construct()
    {
        $this->stringDict = new StringDict();
    }

    public function __destruct()
    {
        if ($this->mmapPtr !== null) {
            LibcFileReader::munmapFile($this->mmapPtr, $this->mmapLength);
            $this->mmapPtr = null;
        }
    }

    public static function open(string $path): self
    {
        $reader = new self();

        // Try mmap first
        $mmapResult = LibcFileReader::mmapFile($path);
        if ($mmapResult !== null) {
            [$reader->mmapPtr, $reader->mmapLength] = $mmapResult;
        } else {
            // Fallback to file_get_contents
            $data = file_get_contents($path);
            if ($data === false) {
                throw new \RuntimeException("Cannot read {$path}");
            }
            $reader->fileData = $data;
        }

        $reader->parseHeader();
        $reader->loadStringDict();
        return $reader;
    }

    private function parseHeader(): void
    {
        $headerBytes = $this->readBytes(0, Format::HEADER_SIZE);
        if (strlen($headerBytes) < Format::HEADER_SIZE) {
            throw new \RuntimeException('File too small for rmem header');
        }

        $magic = substr($headerBytes, 0, 4);
        if ($magic !== Format::MAGIC) {
            throw new \RuntimeException('Invalid rmem magic: ' . bin2hex($magic));
        }

        $version = unpack('V', $headerBytes, 4)[1];
        if ($version !== Format::VERSION) {
            throw new \RuntimeException("Unsupported rmem version: {$version}");
        }

        $flags = unpack('V', $headerBytes, 8)[1];
        if (($flags & Format::FLAG_LITTLE_ENDIAN) === 0) {
            throw new \RuntimeException('Big-endian rmem files are not supported');
        }

        $section_count = unpack('V', $headerBytes, 12)[1];
        $toc_offset = unpack('P', $headerBytes, 16)[1];

        // Parse TOC
        $tocBytes = $this->readBytes((int)$toc_offset, $section_count * Format::TOC_ENTRY_SIZE);
        $pos = 0;
        for ($i = 0; $i < $section_count; $i++) {
            $name = rtrim(substr($tocBytes, $pos, Format::TOC_NAME_SIZE), "\0");
            $pos += Format::TOC_NAME_SIZE;
            $offset = unpack('P', $tocBytes, $pos)[1];
            $pos += 8;
            $length = unpack('P', $tocBytes, $pos)[1];
            $pos += 8;
            $element_count = unpack('P', $tocBytes, $pos)[1];
            $pos += 8;

            $this->toc[$name] = [
                'offset' => (int)$offset,
                'length' => (int)$length,
                'element_count' => (int)$element_count,
            ];
        }
    }

    private function loadStringDict(): void
    {
        if (!isset($this->toc[Format::SECTION_STRING_DICT])) {
            $this->stringDict = new StringDict();
            return;
        }
        $entry = $this->toc[Format::SECTION_STRING_DICT];
        $data = $this->readBytes($entry['offset'], $entry['length']);
        $this->stringDict = StringDict::deserialize($data);
    }

    public function getStringDict(): StringDict
    {
        return $this->stringDict;
    }

    public function hasSection(string $name): bool
    {
        return isset($this->toc[$name]);
    }

    /**
     * Get raw section data as a PHP string.
     *
     * When mmap is active, this copies from the mapped region into a
     * PHP string. Use getSectionPointer() for zero-copy FFI access.
     */
    public function getSectionData(string $name): string
    {
        if (!isset($this->toc[$name])) {
            throw new \RuntimeException("Section not found: {$name}");
        }
        $entry = $this->toc[$name];
        return $this->readBytes($entry['offset'], $entry['length']);
    }

    /**
     * Get a CData pointer to a section's data in the mmap'd region.
     *
     * Returns a `uint8_t*` pointing at the first byte of the section.
     * The caller can FFI::cast this to struct arrays for zero-copy
     * access. Returns null when mmap is not active (fallback mode).
     */
    public function getSectionPointer(string $name): ?CData
    {
        if ($this->mmapPtr === null || !isset($this->toc[$name])) {
            return null;
        }
        $entry = $this->toc[$name];
        $ffi = self::getHelperFfi();
        $base = $ffi->cast('char*', $this->mmapPtr);
        $offset_ptr = $base + $entry['offset'];
        return $ffi->cast('uint8_t*', $offset_ptr);
    }

    /**
     * Whether mmap is active (vs fallback file_get_contents).
     */
    public function isMmapped(): bool
    {
        return $this->mmapPtr !== null;
    }

    public function getSectionElementCount(string $name): int
    {
        if (!isset($this->toc[$name])) {
            return 0;
        }
        return $this->toc[$name]['element_count'];
    }

    public function getSectionOffset(string $name): int
    {
        if (!isset($this->toc[$name])) {
            throw new \RuntimeException("Section not found: {$name}");
        }
        return $this->toc[$name]['offset'];
    }

    public function getSectionLength(string $name): int
    {
        if (!isset($this->toc[$name])) {
            return 0;
        }
        return $this->toc[$name]['length'];
    }

    /**
     * Get the FFI instance with struct definitions for binary row types.
     *
     * Provides packed struct types: NodeRow, EdgeRow, LocationRow.
     * Substrate loaders use these for zero-copy reads from mmap'd sections.
     */
    public static function getStructFfi(): FFI
    {
        if (self::$structFfi === null) {
            self::$structFfi = FFI::cdef('
                typedef struct __attribute__((packed)) {
                    uint32_t node_id;
                    uint32_t canonical_id;
                    uint32_t type_id;
                    uint32_t class_id;
                } NodeRow;

                typedef struct __attribute__((packed)) {
                    uint32_t parent_node_id;
                    uint32_t child_node_id;
                    uint32_t link_name_id;
                    uint8_t  is_tree;
                    uint8_t  strength;
                    uint16_t _pad;
                } EdgeRow;

                typedef struct __attribute__((packed)) {
                    uint32_t node_id;
                    uint32_t location_type_id;
                    uint32_t class_id;
                    uint64_t address;
                    uint64_t size;
                    uint32_t string_value_id;
                    uint32_t refcount;
                    uint32_t type_info;
                    uint32_t region_id;
                    uint32_t bin_overhead;
                } LocationRow;
            ');
        }
        return self::$structFfi;
    }

    /**
     * Cast a section to a typed FFI struct array.
     *
     * The first call allocates an FFI buffer and copies the raw section
     * bytes into it (one bulk memcpy). Subsequent calls with the same
     * section+type return the cached buffer — no re-allocation or copy.
     *
     * @param string $section Section name
     * @param string $type FFI type string, e.g. "NodeRow" or "EdgeRow"
     * @return CData|null The typed array, or null if section missing/empty
     */
    public function castSection(string $section, string $type): ?CData
    {
        $cacheKey = "{$section}:{$type}";
        if (isset($this->castCache[$cacheKey])) {
            return $this->castCache[$cacheKey];
        }

        if (!isset($this->toc[$section])) {
            return null;
        }
        $count = $this->getSectionElementCount($section);
        if ($count === 0) {
            return null;
        }
        $ffi = self::getStructFfi();
        $entry = $this->toc[$section];

        // Verify section size matches expected struct array size
        $needed = $count * self::getStructSize($type);
        if ($entry['length'] < $needed) {
            return null;
        }

        try {
            $buf = $ffi->new("{$type}[{$count}]");
            if ($buf === null) {
                return null;
            }

            if ($this->mmapPtr !== null) {
                $helperFfi = self::getHelperFfi();
                $src = $helperFfi->cast('char*', $this->mmapPtr) + $entry['offset'];
                FFI::memcpy($buf, $src, $needed);
            } else {
                assert($this->fileData !== null);
                $raw = substr($this->fileData, $entry['offset'], $needed);
                FFI::memcpy($buf, $raw, $needed);
            }

            $this->castCache[$cacheKey] = $buf;
            return $buf;
        } catch (\FFI\Exception) {
            return null;
        }
    }

    /** @var array<string, int> type name → sizeof */
    private static array $structSizeCache = [];

    private static function getStructSize(string $type): int
    {
        return self::$structSizeCache[$type]
            ??= FFI::sizeof(self::getStructFfi()->new($type));
    }

    // ---- Internal I/O ----

    /**
     * Read bytes from the underlying storage (mmap or PHP string).
     */
    private function readBytes(int $offset, int $length): string
    {
        if ($this->mmapPtr !== null) {
            $ffi = self::getHelperFfi();
            $base = $ffi->cast('char*', $this->mmapPtr);
            return FFI::string($base + $offset, $length);
        }
        assert($this->fileData !== null);
        return substr($this->fileData, $offset, $length);
    }

    /** Bare FFI instance for non-deprecated cast calls on mmap pointers */
    private static ?FFI $helperFfi = null;

    private static function getHelperFfi(): FFI
    {
        return self::$helperFfi ??= FFI::cdef();
    }
}
