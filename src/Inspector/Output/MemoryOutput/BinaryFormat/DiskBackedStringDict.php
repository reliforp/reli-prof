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

/**
 * Disk-backed string dictionary for large captures.
 *
 * Stores string bodies on disk in a temp file and keeps only an
 * FFI-backed open-addressing hash table in memory. An LRU cache
 * holds frequently accessed strings to avoid repeated disk reads
 * during dedup lookups.
 *
 * Memory per entry: ~37 bytes (28 bytes/slot at 75% load factor)
 * vs ~482 bytes for a PHP nested-array implementation.
 * At 10M entries: ~370 MB vs ~4.6 GB.
 *
 * When the FFI extension is not available, falls back to a compact
 * PHP-array index (~82 bytes/entry with packed binary strings).
 */
final class DiskBackedStringDict
{
    /** @var FfiHashTable|null FFI hash table (null when FFI unavailable) */
    private ?FfiHashTable $ffiTable = null;

    /**
     * Fallback PHP hash index when FFI is not available.
     * Hash → packed binary entries (12 bytes each: id:V, offset:V, len:V).
     * @var array<int, string>|null
     */
    private ?array $phpIndex = null;

    /** Total number of interned strings */
    private int $count = 0;

    /** @var resource disk file for string bodies */
    private $diskFh;
    private string $diskPath;
    private int $diskPos = 0;

    /**
     * LRU cache: id → string value.
     * @var array<int, string>
     */
    private array $cache = [];
    private int $cacheBytes = 0;
    private int $maxCacheBytes;

    /**
     * @param int $max_cache_bytes Maximum bytes of string data to keep in
     *   the LRU cache. Default 64 MiB.
     * @param int $initial_capacity Initial hash table capacity (must be
     *   power of 2 when FFI is used). Default 4096.
     */
    public function __construct(
        int $max_cache_bytes = 64 * 1024 * 1024,
        int $initial_capacity = 4096,
    ) {
        $this->maxCacheBytes = $max_cache_bytes;

        if (extension_loaded('ffi')) {
            $this->ffiTable = new FfiHashTable($initial_capacity);
        } else {
            $this->phpIndex = [];
        }

        $base = tempnam(sys_get_temp_dir(), 'rmem_dict_');
        if ($base === false) {
            throw new \RuntimeException('Failed to create temp file for string dict');
        }
        $this->diskPath = $base;
        $fh = fopen($base, 'w+b');
        if ($fh === false) {
            throw new \RuntimeException('Failed to open dict temp file');
        }
        $this->diskFh = $fh;
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    /**
     * Intern a string with an optional pre-computed hash (from zend_string.h).
     */
    public function intern(?string $s, int $precomputedHash = 0): int
    {
        if ($s === null) {
            return Format::NULL_STRING_ID;
        }

        $len = strlen($s);
        $hash = ($precomputedHash !== 0)
            ? $precomputedHash
            : self::computeHash($s);

        // Look up existing entries with the same hash
        foreach ($this->findCandidates($hash) as [$id, $offset, $entryLen]) {
            if ($entryLen !== $len) {
                continue;
            }
            $existing = $this->readFromDiskOrCache($id, $offset, $entryLen);
            if ($existing === $s) {
                $this->cacheString($id, $s);
                return $id;
            }
        }

        // New string — append to disk
        $id = $this->count;
        $offset = $this->diskPos;
        fwrite($this->diskFh, $s);
        $this->diskPos += $len;
        $this->count++;

        $this->insertEntry($hash, $id, $offset, $len);
        $this->cacheString($id, $s);

        return $id;
    }

    /**
     * Look up a string by id. Returns null for NULL_STRING_ID.
     */
    public function lookup(int $id): ?string
    {
        if ($id === Format::NULL_STRING_ID) {
            return null;
        }
        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }

        foreach ($this->iterateAllEntries() as [$entryId, $offset, $len]) {
            if ($entryId === $id) {
                $s = $this->readFromDisk($offset, $len);
                $this->cacheString($id, $s);
                return $s;
            }
        }
        return null;
    }

    public function count(): int
    {
        return $this->count;
    }

    /**
     * Serialize in StringDict::serialize() format, streaming directly
     * to a file handle. Returns the number of bytes written.
     *
     * @param resource $outFh Target file handle
     * @return int Bytes written
     */
    public function serializeToStream($outFh): int
    {
        /** @var array<int, array{int, int}> $entries */
        $entries = [];
        foreach ($this->iterateAllEntries() as [$id, $offset, $len]) {
            $entries[$id] = [$offset, $len];
        }
        ksort($entries);

        $written = 0;
        $w = fwrite($outFh, pack('V', $this->count));
        $written += $w;

        foreach ($entries as [$offset, $len]) {
            $w = fwrite($outFh, pack('V', $len));
            $written += $w;
            fseek($this->diskFh, $offset);
            $remaining = $len;
            while ($remaining > 0) {
                $chunk = min($remaining, 65536);
                $data = fread($this->diskFh, $chunk);
                if ($data === false || $data === '') {
                    break;
                }
                $w = fwrite($outFh, $data);
                $written += $w;
                $remaining -= strlen($data);
            }
        }
        return $written;
    }

    public function cleanup(): void
    {
        if (is_resource($this->diskFh)) {
            fclose($this->diskFh);
        }
        if (file_exists($this->diskPath)) {
            @unlink($this->diskPath);
        }
    }

    // ---- Index abstraction ----

    /**
     * @return iterable<array{int, int, int}> [id, offset, len]
     */
    private function findCandidates(int $hash): iterable
    {
        if ($this->ffiTable !== null) {
            return $this->ffiTable->findByHash($hash);
        }
        $packed = $this->phpIndex[$hash] ?? null;
        if ($packed === null) {
            return [];
        }
        $result = [];
        $pLen = strlen($packed);
        for ($i = 0; $i < $pLen; $i += 12) {
            $entry = unpack('Vid/Voffset/Vlen', $packed, $i);
            $result[] = [(int)$entry['id'], (int)$entry['offset'], (int)$entry['len']];
        }
        return $result;
    }

    private function insertEntry(int $hash, int $id, int $offset, int $len): void
    {
        if ($this->ffiTable !== null) {
            $this->ffiTable->insert($hash, $id, $offset, $len);
            return;
        }
        $this->phpIndex[$hash] = ($this->phpIndex[$hash] ?? '') . pack('VVV', $id, $offset, $len);
    }

    /**
     * @return iterable<array{int, int, int}> [id, offset, len]
     */
    private function iterateAllEntries(): iterable
    {
        if ($this->ffiTable !== null) {
            return $this->ffiTable->iterateAll();
        }
        $result = [];
        foreach ($this->phpIndex as $packed) {
            $pLen = strlen($packed);
            for ($i = 0; $i < $pLen; $i += 12) {
                $entry = unpack('Vid/Voffset/Vlen', $packed, $i);
                $result[] = [(int)$entry['id'], (int)$entry['offset'], (int)$entry['len']];
            }
        }
        return $result;
    }

    // ---- Cache & disk I/O ----

    private function readFromDiskOrCache(int $id, int $offset, int $len): string
    {
        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }
        return $this->readFromDisk($offset, $len);
    }

    private function readFromDisk(int $offset, int $len): string
    {
        fseek($this->diskFh, $offset);
        $data = fread($this->diskFh, $len);
        if ($data === false || strlen($data) !== $len) {
            throw new \RuntimeException("Failed to read {$len} bytes at offset {$offset} from dict disk");
        }
        return $data;
    }

    private function cacheString(int $id, string $s): void
    {
        if (isset($this->cache[$id])) {
            return;
        }
        $len = strlen($s);
        while ($this->cacheBytes + $len > $this->maxCacheBytes && $this->cache !== []) {
            $evictId = array_key_first($this->cache);
            $this->cacheBytes -= strlen($this->cache[$evictId]);
            unset($this->cache[$evictId]);
        }
        if ($len <= $this->maxCacheBytes) {
            $this->cache[$id] = $s;
            $this->cacheBytes += $len;
        }
    }

    private static function computeHash(string $s): int
    {
        $h = crc32($s);
        return $h !== 0 ? $h : 1;
    }
}
