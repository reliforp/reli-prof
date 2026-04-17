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
use Reli\Lib\FFI\FFIHelper;

/**
 * Reads a .rmem.derived sidecar cache file.
 *
 * Validates the cache against the rmem file's mtime and size.
 * Returns null from open() if the cache is invalid or missing.
 */
final class DerivedCacheReader
{
    /** @var array<string, array{offset: int, length: int, element_count: int}> */
    private array $toc = [];
    private string $data;

    private function __construct(string $data)
    {
        $this->data = $data;
    }

    /**
     * Open and validate a sidecar cache for the given rmem file.
     * Returns null if the cache is missing, invalid, or stale.
     */
    public static function open(string $rmemPath): ?self
    {
        $cachePath = $rmemPath . '.derived';
        if (!file_exists($cachePath)) {
            return null;
        }

        clearstatcache(true, $rmemPath);
        $rmemStat = @stat($rmemPath);
        if ($rmemStat === false) {
            return null;
        }

        $data = @file_get_contents($cachePath);
        if ($data === false || strlen($data) < DerivedCacheFormat::HEADER_SIZE) {
            return null;
        }

        // Validate magic
        $magic = substr($data, 0, 4);
        if ($magic !== DerivedCacheFormat::MAGIC) {
            return null;
        }

        // Validate version
        $version = unpack('V', $data, 4)[1];
        if ($version !== DerivedCacheFormat::VERSION) {
            return null;
        }

        // Validate rmem mtime + size
        $storedMtime = unpack('P', $data, 8)[1];
        $storedSize = unpack('P', $data, 16)[1];
        $currentMtime = (int)($rmemStat['mtime'] * 1_000_000);
        $currentSize = $rmemStat['size'];

        if ($storedMtime !== $currentMtime || $storedSize !== $currentSize) {
            return null;
        }

        // Parse TOC
        $sectionCount = unpack('V', $data, 24)[1];
        $tocOffset = unpack('V', $data, 28)[1];

        $reader = new self($data);
        $pos = (int)$tocOffset;
        for ($i = 0; $i < $sectionCount; $i++) {
            $name = rtrim(substr($data, $pos, DerivedCacheFormat::TOC_NAME_SIZE), "\0");
            $pos += DerivedCacheFormat::TOC_NAME_SIZE;
            $offset = unpack('P', $data, $pos)[1];
            $pos += 8;
            $length = unpack('P', $data, $pos)[1];
            $pos += 8;
            $elementCount = unpack('P', $data, $pos)[1];
            $pos += 8;

            $reader->toc[$name] = [
                'offset' => (int)$offset,
                'length' => (int)$length,
                'element_count' => (int)$elementCount,
            ];
        }

        return $reader;
    }

    public function hasSection(string $name): bool
    {
        return isset($this->toc[$name]);
    }

    public function getSectionElementCount(string $name): int
    {
        return $this->toc[$name]['element_count'] ?? 0;
    }

    /**
     * Load a section into an FFI int32 array.
     */
    public function loadInt32Section(string $name): ?\FFI\CData
    {
        if (!isset($this->toc[$name])) {
            return null;
        }
        $entry = $this->toc[$name];
        $count = $entry['element_count'];
        if ($count === 0) {
            return null;
        }
        $expected = $count * 4;
        if ($entry['length'] < $expected) {
            return null;
        }
        $buf = FFIHelper::new("int32_t[{$count}]");
        FFI::memcpy($buf, substr($this->data, $entry['offset'], $expected), $expected);
        return $buf;
    }

    /**
     * Load a section into an FFI int64 array.
     */
    public function loadInt64Section(string $name): ?\FFI\CData
    {
        if (!isset($this->toc[$name])) {
            return null;
        }
        $entry = $this->toc[$name];
        $count = $entry['element_count'];
        if ($count === 0) {
            return null;
        }
        $expected = $count * 8;
        if ($entry['length'] < $expected) {
            return null;
        }
        $buf = FFIHelper::new("int64_t[{$count}]");
        FFI::memcpy($buf, substr($this->data, $entry['offset'], $expected), $expected);
        return $buf;
    }

    /**
     * Load a section as a raw string.
     */
    public function getSectionData(string $name): ?string
    {
        if (!isset($this->toc[$name])) {
            return null;
        }
        $entry = $this->toc[$name];
        return substr($this->data, $entry['offset'], $entry['length']);
    }
}
