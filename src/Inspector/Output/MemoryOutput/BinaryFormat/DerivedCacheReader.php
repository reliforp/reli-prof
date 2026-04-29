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
 * Validates the cache against mtime + size + a SHA-256 structural
 * fingerprint of the rmem header and TOC.
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
        // The runtime check is required: PHP keeps the value as a
        // `closed-resource` after the first fclose(), and is_resource()
        // returns false for that. The docblock-vs-runtime mismatch
        // (declared `resource`, may actually be `closed-resource` mid-
        // destruct) is the very thing Psalm flags here, so suppress
        // narrowly with a comment rather than papering over it with
        // `resource|closed-resource` — that'd cascade PossiblyInvalid-
        // Argument complaints across every fseek / fread call below.
        /** @psalm-suppress RedundantConditionGivenDocblockType */
        if (is_resource($this->fh)) {
            fclose($this->fh);
        }
    }

    /**
     * Open and validate a sidecar cache for the given rmem file.
     * Returns null if the cache is missing, invalid, or stale.
     *
     * Each unpack(...)[1] in this method is guarded by a preceding
     * fread + strlen check, so the |false branch is unreachable.
     * Suppressing PossiblyInvalidArrayAccess at the method level is
     * cheaper than capturing every unpack() result into a local with
     * assert($r !== false) — this is the cold cache-validation path,
     * not a hot loop.
     *
     * @psalm-suppress PossiblyInvalidArrayAccess
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
        fseek($fh, $tocOffset);
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
                'offset' => $offset,
                'length' => $length,
                'element_count' => $elementCount,
            ];
        }

        // Validate rmem content fingerprint: SHA-256 of rmem header + TOC.
        // VERSION was bumped to 2, so old caches without __fingerprint
        // are already rejected by the version check above.
        if (!isset($reader->toc['__fingerprint'])) {
            return null;
        }
        $fpEntry = $reader->toc['__fingerprint'];
        fseek($reader->fh, $fpEntry['offset']);
        $storedFp = fread($reader->fh, $fpEntry['length']);
        if ($storedFp === false || strlen($storedFp) !== 32) {
            return null;
        }

        $rmemFh = @fopen($rmemPath, 'rb');
        if ($rmemFh === false) {
            return null;
        }
        $rmemHeader = fread($rmemFh, 64);
        if ($rmemHeader === false || strlen($rmemHeader) < 24) {
            fclose($rmemFh);
            return null;
        }
        $rmemSectionCount = unpack('V', $rmemHeader, 12)[1];
        $rmemTocOffset = unpack('P', $rmemHeader, 16)[1];
        $rmemTocSize = $rmemSectionCount * 40;
        $rmemTocData = '';
        if ($rmemTocSize > 0) {
            fseek($rmemFh, $rmemTocOffset);
            $rmemTocData = fread($rmemFh, $rmemTocSize);
            if ($rmemTocData === false) {
                $rmemTocData = '';
            }
        }
        fclose($rmemFh);
        $currentFp = hash('sha256', $rmemHeader . $rmemTocData, true);
        if ($storedFp !== $currentFp) {
            return null;
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
     *
     * @return \FFI\CArray<int>|null
     */
    public function loadInt32Section(string $name): ?\FFI\CData
    {
        return $this->loadFfiSection($name, 'int32_t', 4);
    }

    /**
     * Load a section into an FFI int64 array via chunked fread + memcpy.
     *
     * @return \FFI\CArray<int>|null
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
        if ($data === false || strlen($data) !== $entry['length']) {
            return null;
        }
        return $data;
    }

    /**
     * Generic chunked loader: fread in chunks and memcpy into FFI buffer.
     * Avoids creating a single PHP string for the entire section.
     *
     * `FFI::addr($buf[$elemOffset])` is the documented way to take the
     * address of an array element for memcpy; the union the stub gives
     * `CData::offsetGet()` is wider than what ext/ffi actually returns
     * for `int32_t[]` / `int64_t[]` elements.
     *
     * @psalm-suppress PossiblyInvalidArgument
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

        $buf = FFIHelper::new("{$type}[{$count}]");

        // Read in element-aligned chunks directly into the destination
        // FFI buffer via FFI::addr($buf[$elemOffset]). Peak overhead is
        // just the ~4 MB PHP string chunk per iteration.
        fseek($this->fh, $entry['offset']);
        $chunkElems = (int)(4 * 1024 * 1024 / $elemSize); // ~4 MB in elements
        if ($chunkElems < 1) {
            $chunkElems = 1;
        }
        $elemOffset = 0;
        while ($elemOffset < $count) {
            $batch = min($chunkElems, $count - $elemOffset);
            $bytes = $batch * $elemSize;
            $chunk = fread($this->fh, $bytes);
            if ($chunk === false || strlen($chunk) !== $bytes) {
                return null;
            }
            FFI::memcpy(FFI::addr($buf[$elemOffset]), $chunk, $bytes);
            $elemOffset += $batch;
        }
        return $buf;
    }
}
