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
 * Uses fseek/fread for section access — never loads the whole file
 * into a PHP string. Large FFI sections are read in chunks directly
 * into FFI buffers via FFI::memcpy to avoid PHP string temporaries.
 *
 * Validates the cache against the rmem file's fingerprint (section
 * count + TOC offset + size) to detect same-second overwrites.
 */
final class DerivedCacheReader
{
    /** @var array<string, array{offset: int, length: int, element_count: int}> */
    private array $toc = [];

    /** @var resource */
    private mixed $fh;

    /**
     * @param resource $fh
     */
    private function __construct(mixed $fh)
    {
        $this->fh = $fh;
    }

    public function __destruct()
    {
        if (is_resource($this->fh)) {
            fclose($this->fh);
        }
    }

    /**
     * Open and validate a sidecar cache for the given rmem file.
     * Returns null if the cache is missing, invalid, or stale.
     */
    public static function open(string $rmemPath): ?self
    {
        $cachePath = $rmemPath . '.derived';
        $fh = @fopen($cachePath, 'rb');
        if ($fh === false) {
            return null;
        }

        $headerBytes = fread($fh, DerivedCacheFormat::HEADER_SIZE);
        if ($headerBytes === false || strlen($headerBytes) < DerivedCacheFormat::HEADER_SIZE) {
            fclose($fh);
            return null;
        }

        if (substr($headerBytes, 0, 4) !== DerivedCacheFormat::MAGIC) {
            fclose($fh);
            return null;
        }
        if (unpack('V', $headerBytes, 4)[1] !== DerivedCacheFormat::VERSION) {
            fclose($fh);
            return null;
        }

        // Validate rmem fingerprint (mtime + size + content fingerprint)
        $storedMtime = unpack('P', $headerBytes, 8)[1];
        $storedSize = unpack('P', $headerBytes, 16)[1];

        clearstatcache(true, $rmemPath);
        $rmemStat = @stat($rmemPath);
        if ($rmemStat === false) {
            fclose($fh);
            return null;
        }
        $currentMtime = (int)($rmemStat['mtime'] * 1_000_000);
        $currentSize = $rmemStat['size'];
        if ($storedMtime !== $currentMtime || $storedSize !== $currentSize) {
            fclose($fh);
            return null;
        }

        $sectionCount = unpack('V', $headerBytes, 24)[1];
        $tocOffset = unpack('V', $headerBytes, 28)[1];

        // Validate rmem content fingerprint (from rmem header+TOC)
        $storedFingerprint = '';
        if (strlen($headerBytes) >= DerivedCacheFormat::HEADER_SIZE) {
            // fingerprint is at offset 32..39 if header is 40 bytes
            // For VERSION=1 with 32-byte header, fingerprint is not present.
            // We validate via section_count match from rmem TOC below.
        }

        // Parse TOC
        fseek($fh, (int)$tocOffset);
        $tocBytes = fread($fh, $sectionCount * DerivedCacheFormat::TOC_ENTRY_SIZE);
        if ($tocBytes === false || strlen($tocBytes) < $sectionCount * DerivedCacheFormat::TOC_ENTRY_SIZE) {
            fclose($fh);
            return null;
        }

        $reader = new self($fh);
        $pos = 0;
        for ($i = 0; $i < $sectionCount; $i++) {
            $name = rtrim(substr($tocBytes, $pos, DerivedCacheFormat::TOC_NAME_SIZE), "\0");
            $pos += DerivedCacheFormat::TOC_NAME_SIZE;
            $offset = unpack('P', $tocBytes, $pos)[1];
            $pos += 8;
            $length = unpack('P', $tocBytes, $pos)[1];
            $pos += 8;
            $elementCount = unpack('P', $tocBytes, $pos)[1];
            $pos += 8;

            $reader->toc[$name] = [
                'offset' => (int)$offset,
                'length' => (int)$length,
                'element_count' => (int)$elementCount,
            ];
        }

        // Validate rmem content fingerprint: compare stored rmem section
        // count + TOC offset against current rmem file. This catches
        // same-second same-size overwrites that mtime+size would miss.
        $rmemFh = @fopen($rmemPath, 'rb');
        if ($rmemFh !== false) {
            $rmemHeader = fread($rmemFh, 24);
            fclose($rmemFh);
            if ($rmemHeader !== false && strlen($rmemHeader) >= 24) {
                $rmemSectionCount = unpack('V', $rmemHeader, 12)[1];
                $rmemTocOffset = unpack('P', $rmemHeader, 16)[1];
                $currentFp = pack('VP', $rmemSectionCount, $rmemTocOffset);

                // Read stored fingerprint from sidecar (bytes 32..41)
                if (isset($reader->toc['__fingerprint'])) {
                    $fpEntry = $reader->toc['__fingerprint'];
                    fseek($reader->fh, $fpEntry['offset']);
                    $storedFp = fread($reader->fh, $fpEntry['length']);
                    if ($storedFp !== $currentFp) {
                        return null;
                    }
                }
                // If no __fingerprint section, skip fingerprint check
                // (backwards compat with older sidecar files).
            }
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
     * Load a section into an FFI int32 array via chunked fread + memcpy.
     */
    public function loadInt32Section(string $name): ?\FFI\CData
    {
        return $this->loadFfiSection($name, 'int32_t', 4);
    }

    /**
     * Load a section into an FFI int64 array via chunked fread + memcpy.
     */
    public function loadInt64Section(string $name): ?\FFI\CData
    {
        return $this->loadFfiSection($name, 'int64_t', 8);
    }

    /**
     * Load a section as a raw string (for small sections like JSON profiles).
     */
    public function getSectionData(string $name): ?string
    {
        if (!isset($this->toc[$name])) {
            return null;
        }
        $entry = $this->toc[$name];
        fseek($this->fh, $entry['offset']);
        $data = fread($this->fh, $entry['length']);
        return $data === false ? null : $data;
    }

    /**
     * Generic chunked loader: fread in chunks and memcpy into FFI buffer.
     * Avoids creating a single PHP string for the entire section.
     */
    private function loadFfiSection(string $name, string $type, int $elemSize): ?\FFI\CData
    {
        if (!isset($this->toc[$name])) {
            return null;
        }
        $entry = $this->toc[$name];
        $count = $entry['element_count'];
        if ($count === 0) {
            return null;
        }
        $totalBytes = $count * $elemSize;
        if ($entry['length'] < $totalBytes) {
            return null;
        }

        // Allocate a flat char buffer, read into it in chunks, then
        // cast to the typed array. This avoids a single huge PHP string
        // (the codex review concern) while sidestepping FFI::addr
        // segfaults on typed array elements.
        $buf = FFIHelper::new("{$type}[{$count}]");

        // Read in 4 MB chunks. Each chunk is fread into a PHP string,
        // then memcpy'd into the target FFI buffer via a temporary
        // char[] owned by a bare FFI instance (safe for pointer math).
        fseek($this->fh, $entry['offset']);
        $chunkSize = 4 * 1024 * 1024;
        $offset = 0;
        $ffi = FFI::cdef();
        // We need a char* view of $buf for offset writes.
        // memcpy into a flat staging buffer, then bulk-copy into $buf.
        $staging = $ffi->new("char[{$totalBytes}]");
        while ($offset < $totalBytes) {
            $toRead = min($chunkSize, $totalBytes - $offset);
            $chunk = fread($this->fh, $toRead);
            if ($chunk === false || strlen($chunk) === 0) {
                return null;
            }
            $read = strlen($chunk);
            FFI::memcpy($staging + $offset, $chunk, $read);
            $offset += $read;
        }
        FFI::memcpy($buf, $staging, $totalBytes);
        return $buf;
    }
}
