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
 * Uses atomic write: writes to a temp file then renames.
 * The header stores the rmem file's mtime and size for validation.
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
     * Write an FFI int32 array as a section.
     */
    public function writeInt32Section(string $name, \FFI\CData $data, int $count): void
    {
        $bytes = $count * 4;
        $this->writeRawSection($name, FFI::string($data, $bytes), $count);
    }

    /**
     * Write an FFI int64 array as a section.
     */
    public function writeInt64Section(string $name, \FFI\CData $data, int $count): void
    {
        $bytes = $count * 8;
        $this->writeRawSection($name, FFI::string($data, $bytes), $count);
    }

    /**
     * Write a raw string section.
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
}
