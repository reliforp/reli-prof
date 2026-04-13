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
 * Stores string bodies on disk in a temp file and keeps only a hash-based
 * index in memory. An LRU cache holds frequently accessed strings to avoid
 * repeated disk reads during dedup lookups.
 *
 * For zend_string values, the pre-computed hash from zend_string.h is used
 * when available (non-zero). For other strings (link_name, class_name, etc.)
 * a CRC32c hash is computed on the fly.
 *
 * Memory usage: ~40 bytes per entry (hash index) + configurable cache.
 * Compare: in-memory StringDict uses ~120+ bytes per entry (PHP string +
 * array bucket overhead).
 *
 * Write path (intern):
 *   1. Compute or reuse hash
 *   2. Look up hash in index → candidate list
 *   3. For each candidate: compare length, then compare bytes from disk/cache
 *   4. If match → return existing id
 *   5. If no match → append to disk, add to index
 *
 * The final .rmem serialization uses StringDict::serialize() format for
 * compatibility. Call toStringDict() to produce the serialized form by
 * streaming from disk.
 */
final class DiskBackedStringDict
{
    /**
     * Hash index: hash → list of candidate entries.
     * Each entry: [id, diskOffset, length]
     *
     * @var array<int, list<array{int, int, int}>>
     */
    private array $hashIndex = [];

    /** Total number of interned strings */
    private int $count = 0;

    /** @var resource disk file for string bodies */
    private $diskFh;
    private string $diskPath;
    private int $diskPos = 0;

    /**
     * LRU cache: id → string value.
     * Bounded by $maxCacheBytes. Eviction is approximate (FIFO via array_shift).
     *
     * @var array<int, string>
     */
    private array $cache = [];
    private int $cacheBytes = 0;
    private int $maxCacheBytes;

    /**
     * @param int $max_cache_bytes Maximum bytes of string data to keep in the
     *   LRU cache. Default 64 MiB. Set lower on memory-constrained machines.
     */
    public function __construct(int $max_cache_bytes = 64 * 1024 * 1024)
    {
        $this->maxCacheBytes = $max_cache_bytes;

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
     *
     * @param string|null $s The string to intern (null → NULL_STRING_ID)
     * @param int $precomputedHash Hash from zend_string.h (0 = not available)
     * @return int The string's id in the dictionary
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
        if (isset($this->hashIndex[$hash])) {
            foreach ($this->hashIndex[$hash] as [$id, $offset, $entryLen]) {
                if ($entryLen !== $len) {
                    continue;
                }
                // Length matches — compare actual bytes
                $existing = $this->readFromDiskOrCache($id, $offset, $entryLen);
                if ($existing === $s) {
                    // Cache the looked-up string (it's being reused)
                    $this->cacheString($id, $s);
                    return $id;
                }
            }
        }

        // New string — append to disk
        $id = $this->count;
        $offset = $this->diskPos;
        fwrite($this->diskFh, $s);
        $this->diskPos += $len;
        $this->count++;

        $this->hashIndex[$hash][] = [$id, $offset, $len];
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
        // Linear scan through hash index to find the entry by id.
        // This is O(N/buckets) but lookup() is not on the hot write path.
        foreach ($this->hashIndex as $entries) {
            foreach ($entries as [$entryId, $offset, $len]) {
                if ($entryId === $id) {
                    $s = $this->readFromDisk($offset, $len);
                    $this->cacheString($id, $s);
                    return $s;
                }
            }
        }
        return null;
    }

    public function count(): int
    {
        return $this->count;
    }

    /**
     * Serialize in the same format as StringDict::serialize(), but stream
     * directly to a file handle rather than building a PHP string. Returns
     * the number of bytes written.
     *
     * @param resource $outFh Target file handle
     * @return int Bytes written
     */
    public function serializeToStream($outFh): int
    {
        // Build id → (offset, len) mapping from the hash index, sorted by id
        /** @var array<int, array{int, int}> $entries id => [diskOffset, len] */
        $entries = [];
        foreach ($this->hashIndex as $candidates) {
            foreach ($candidates as [$id, $offset, $len]) {
                $entries[$id] = [$offset, $len];
            }
        }
        ksort($entries);

        $written = 0;
        $w = fwrite($outFh, pack('V', $this->count));
        $written += $w;

        foreach ($entries as [$offset, $len]) {
            $w = fwrite($outFh, pack('V', $len));
            $written += $w;
            // Stream the string body from disk in chunks
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

    /**
     * Cleanup disk resources.
     */
    public function cleanup(): void
    {
        if (is_resource($this->diskFh)) {
            fclose($this->diskFh);
        }
        if (file_exists($this->diskPath)) {
            @unlink($this->diskPath);
        }
    }

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
        // Evict if over budget
        while ($this->cacheBytes + $len > $this->maxCacheBytes && $this->cache !== []) {
            $evictId = array_key_first($this->cache);
            $this->cacheBytes -= strlen($this->cache[$evictId]);
            unset($this->cache[$evictId]);
        }
        // Don't cache strings larger than the entire cache budget
        if ($len <= $this->maxCacheBytes) {
            $this->cache[$id] = $s;
            $this->cacheBytes += $len;
        }
    }

    /**
     * Compute a hash for strings that don't have a pre-computed one.
     * Uses CRC32c for speed (hardware-accelerated on modern x86).
     */
    private static function computeHash(string $s): int
    {
        // crc32c returns a 32-bit value. We want a non-zero int to
        // distinguish from "hash not computed" (0). If CRC32c happens
        // to return 0, use 1 instead.
        $h = crc32($s);
        return $h !== 0 ? $h : 1;
    }
}
