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

/**
 * Writes a .rmem.derived sidecar cache file.
 *
 * Uses atomic write (temp file → rename). FFI sections are written
 * in chunks via FFI::string to avoid materializing a single PHP string
 * for the entire section.
 *
 * Stores an rmem content fingerprint (section_count + toc_offset from
 * the rmem header) to detect same-second same-size overwrites.
 */
final class DerivedCacheWriter
{
    /** @var resource|null */
    private $fh;
    private string $tempPath;
    private string $finalPath;
    private int $rmemMtime;
    private int $rmemSize;

    /** @var list<array{name: string, offset: int, length: int, element_count: int}> */
    private array $toc = [];
    private int $pos;

    private function __construct(
        string $finalPath,
        string $tempPath,
        int $rmemMtime,
        int $rmemSize,
        /** @var resource */
        mixed $fh,
    ) {
        $this->finalPath = $finalPath;
        $this->tempPath = $tempPath;
        $this->rmemMtime = $rmemMtime;
        $this->rmemSize = $rmemSize;
        $this->fh = $fh;

        // Reserve header space
        fwrite($this->fh, str_repeat("\0", DerivedCacheFormat::HEADER_SIZE));
        $this->pos = DerivedCacheFormat::HEADER_SIZE;
    }

    /**
     * Create a writer for the given rmem file path.
     * Returns null if the temp file cannot be created (read-only dir, etc.).
     */
    public static function create(string $rmemPath): ?self
    {
        $stat = @stat($rmemPath);
        if ($stat === false) {
            return null;
        }

        $finalPath = $rmemPath . '.derived';
        $tempPath = $finalPath . '.tmp.' . getmypid();

        $fh = @fopen($tempPath, 'wb');
        if ($fh === false) {
            return null;
        }

        return new self(
            $finalPath,
            $tempPath,
            (int)($stat['mtime'] * 1_000_000),
            $stat['size'],
            $fh,
        );
    }

    /**
     * Write an FFI int32 array as a section using chunked FFI::string.
     */
    public function writeInt32Section(string $name, \FFI\CData $data, int $count): void
    {
        $this->writeFfiSection($name, $data, $count, 4);
    }

    /**
     * Write an FFI int64 array as a section using chunked FFI::string.
     */
    public function writeInt64Section(string $name, \FFI\CData $data, int $count): void
    {
        $this->writeFfiSection($name, $data, $count, 8);
    }

    /**
     * Write a raw string section (for small sections like JSON profiles).
     */
    public function writeRawSection(string $name, string $data, int $elementCount): void
    {
        if ($this->fh === null) {
            return;
        }
        $offset = $this->pos;
        $length = strlen($data);
        fwrite($this->fh, $data);
        $this->pos += $length;

        $this->toc[] = [
            'name' => $name,
            'offset' => $offset,
            'length' => $length,
            'element_count' => $elementCount,
        ];
    }

    /**
     * Write rmem content fingerprint (section_count + toc_offset).
     * Called by FfiCsrGraphSubstrate after writing all derived sections.
     */
    /**
     * Store a SHA-256 hash of the rmem file's header + TOC bytes as a
     * content fingerprint. This catches same-second same-size overwrites
     * that mtime + size alone would miss. The TOC encodes section names,
     * offsets, sizes, and element counts — any structural change to the
     * rmem invalidates the derived cache.
     */
    public function writeFingerprint(string $rmemPath): void
    {
        $rmemFh = @fopen($rmemPath, 'rb');
        if ($rmemFh === false) {
            return;
        }
        // Read rmem header (64 bytes) to find TOC location
        $rmemHeader = fread($rmemFh, 64);
        if ($rmemHeader === false || strlen($rmemHeader) < 24) {
            fclose($rmemFh);
            return;
        }
        $sectionCount = unpack('V', $rmemHeader, 12)[1];
        $tocOffset = unpack('P', $rmemHeader, 16)[1];
        $tocSize = $sectionCount * 40; // TOC_ENTRY_SIZE = 40

        // Read the full TOC
        $tocData = '';
        if ($tocSize > 0) {
            fseek($rmemFh, (int)$tocOffset);
            $tocData = fread($rmemFh, $tocSize);
            if ($tocData === false) {
                $tocData = '';
            }
        }
        fclose($rmemFh);

        // Hash header + TOC
        $fp = hash('sha256', $rmemHeader . $tocData, true); // 32 bytes
        $this->writeRawSection('__fingerprint', $fp, 1);
    }

    /**
     * Finalize: write TOC, header, and atomically rename.
     *
     * Re-stats the rmem file before rename. If it changed since
     * construction, the temp file is discarded (stale-writer guard).
     *
     * Returns true on success, false on any failure (fail-open).
     */
    public function finish(string $rmemPath): bool
    {
        if ($this->fh === null) {
            return false;
        }

        // Write TOC
        $tocOffset = $this->pos;
        foreach ($this->toc as $entry) {
            $namePadded = str_pad($entry['name'], DerivedCacheFormat::TOC_NAME_SIZE, "\0");
            fwrite($this->fh, substr($namePadded, 0, DerivedCacheFormat::TOC_NAME_SIZE));
            fwrite($this->fh, pack('P', $entry['offset']));
            fwrite($this->fh, pack('P', $entry['length']));
            fwrite($this->fh, pack('P', $entry['element_count']));
        }

        // Write header
        fseek($this->fh, 0);
        $header = DerivedCacheFormat::MAGIC;               // 4 bytes
        $header .= pack('V', DerivedCacheFormat::VERSION); // 4 bytes
        $header .= pack('P', $this->rmemMtime);            // 8 bytes
        $header .= pack('P', $this->rmemSize);             // 8 bytes
        $header .= pack('V', count($this->toc));           // 4 bytes: section_count
        $header .= pack('V', $tocOffset);                  // 4 bytes: toc_offset
        fwrite($this->fh, $header);

        fclose($this->fh);
        $this->fh = null;

        // Stale-writer guard: re-stat rmem
        clearstatcache(true, $rmemPath);
        $stat = @stat($rmemPath);
        if ($stat === false) {
            @unlink($this->tempPath);
            return false;
        }
        $currentMtime = (int)($stat['mtime'] * 1_000_000);
        $currentSize = $stat['size'];
        if ($currentMtime !== $this->rmemMtime || $currentSize !== $this->rmemSize) {
            @unlink($this->tempPath);
            return false;
        }

        // Atomic rename
        if (!@rename($this->tempPath, $this->finalPath)) {
            @unlink($this->tempPath);
            return false;
        }

        return true;
    }

    public function __destruct()
    {
        if ($this->fh !== null) {
            fclose($this->fh);
            $this->fh = null;
            @unlink($this->tempPath);
        }
    }

    /**
     * Chunked writer for FFI arrays. Converts FFI buffer to PHP strings
     * in 4 MB chunks instead of one huge FFI::string call.
     */
    private function writeFfiSection(string $name, \FFI\CData $data, int $count, int $elemSize): void
    {
        if ($this->fh === null) {
            return;
        }
        $totalBytes = $count * $elemSize;
        $offset = $this->pos;

        // Write in element-aligned chunks via FFI::addr($data[$offset])
        // + FFI::string. No staging buffer — peak overhead is just the
        // ~4 MB PHP string chunk.
        $chunkElems = (int)(4 * 1024 * 1024 / $elemSize); // ~4 MB in elements
        if ($chunkElems < 1) {
            $chunkElems = 1;
        }
        $elemOffset = 0;
        while ($elemOffset < $count) {
            $batch = min($chunkElems, $count - $elemOffset);
            $bytes = $batch * $elemSize;
            $chunk = FFI::string(FFI::addr($data[$elemOffset]), $bytes);
            fwrite($this->fh, $chunk);
            $elemOffset += $batch;
        }
        $this->pos += $totalBytes;

        $this->toc[] = [
            'name' => $name,
            'offset' => $offset,
            'length' => $totalBytes,
            'element_count' => $count,
        ];
    }
}
