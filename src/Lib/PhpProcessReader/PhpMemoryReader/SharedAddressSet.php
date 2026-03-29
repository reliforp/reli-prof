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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader;

/**
 * A lock-free concurrent hash set backed by a C atomic hash map
 * allocated via libc malloc (shared across ext-parallel threads).
 *
 * Uses CAS (compare-and-swap) for thread-safe insert.
 * Keys are int64 memory addresses; 0 is reserved as "empty slot".
 */
final class SharedAddressSet
{
    private const C_SOURCE = <<<'C'
#include <stdint.h>
#include <stdatomic.h>
#include <stdlib.h>

typedef struct {
    _Atomic(int64_t) *slots;
    int64_t capacity;
    int64_t mask;
} SharedHashMap;

SharedHashMap *hashmap_create(int64_t capacity) {
    SharedHashMap *map = calloc(1, sizeof(SharedHashMap));
    map->slots = calloc(capacity, sizeof(_Atomic(int64_t)));
    map->capacity = capacity;
    map->mask = capacity - 1;
    return map;
}
void hashmap_destroy(SharedHashMap *map) { free(map->slots); free(map); }
int hashmap_try_insert(SharedHashMap *map, int64_t key) {
    int64_t slot = key & map->mask;
    for (int p = 0; p < 128; p++) {
        int64_t idx = (slot + p) & map->mask;
        int64_t exp = 0;
        if (atomic_compare_exchange_strong(&map->slots[idx], &exp, key)) return 1;
        if (exp == key) return 0;
    }
    return -1;
}
int hashmap_has(SharedHashMap *map, int64_t key) {
    int64_t slot = key & map->mask;
    for (int p = 0; p < 128; p++) {
        int64_t idx = (slot + p) & map->mask;
        int64_t val = atomic_load(&map->slots[idx]);
        if (val == key) return 1;
        if (val == 0) return 0;
    }
    return 0;
}
int64_t hashmap_addr(SharedHashMap *map) { return (int64_t)map; }
SharedHashMap *hashmap_from_addr(int64_t addr) { return (SharedHashMap *)addr; }
C;

    private const FFI_HEADER = <<<'H'
typedef struct SharedHashMap SharedHashMap;
SharedHashMap *hashmap_create(long capacity);
void hashmap_destroy(SharedHashMap *map);
int hashmap_try_insert(SharedHashMap *map, long key);
int hashmap_has(SharedHashMap *map, long key);
long hashmap_addr(SharedHashMap *map);
SharedHashMap *hashmap_from_addr(long addr);
H;

    private \FFI $ffi;
    /** @var \FFI\CData  SharedHashMap* */
    private \FFI\CData $map;
    private bool $owns_map;

    private function __construct(\FFI $ffi, \FFI\CData $map, bool $owns_map)
    {
        $this->ffi = $ffi;
        $this->map = $map;
        $this->owns_map = $owns_map;
    }

    public function __destruct()
    {
        if ($this->owns_map) {
            $this->ffi->hashmap_destroy($this->map);
        }
    }

    public static function isAvailable(): bool
    {
        try {
            self::ensureSo();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function getSoPath(): string
    {
        return self::ensureSo();
    }

    /**
     * Create a new shared set with the given capacity (must be power of 2).
     */
    public static function create(int $capacity = 1 << 22): self
    {
        $so_path = self::ensureSo();
        $ffi = \FFI::cdef(self::FFI_HEADER, $so_path);
        $map = $ffi->hashmap_create($capacity);
        return new self($ffi, $map, true);
    }

    /**
     * Reconstruct from a raw address (for use in worker threads).
     */
    public static function fromAddr(int $addr, ?string $so_path = null): self
    {
        $so_path ??= self::ensureSo();
        $ffi = \FFI::cdef(self::FFI_HEADER, $so_path);
        $map = $ffi->hashmap_from_addr($addr);
        return new self($ffi, $map, false);
    }

    /**
     * Get the raw address for passing to worker threads via Channel.
     */
    public function getAddr(): int
    {
        return $this->ffi->hashmap_addr($this->map);
    }

    /**
     * Try to insert a key. Returns true if this call inserted it
     * (i.e., this thread "owns" the key), false if already present.
     */
    public function tryInsert(int $key): bool
    {
        return $this->ffi->hashmap_try_insert($this->map, $key) === 1;
    }

    /**
     * Check if a key is present.
     */
    public function has(int $key): bool
    {
        return $this->ffi->hashmap_has($this->map, $key) === 1;
    }

    /**
     * Compile and cache the .so file.
     */
    private static function ensureSo(): string
    {
        $cache_dir = sys_get_temp_dir();
        $so_path = $cache_dir . '/reli_cas_hashmap.so';

        if (file_exists($so_path)) {
            return $so_path;
        }

        $c_path = $cache_dir . '/reli_cas_hashmap.c';
        file_put_contents($c_path, self::C_SOURCE);
        $cmd = "gcc -shared -fPIC -O2 -o " . escapeshellarg($so_path)
            . " " . escapeshellarg($c_path) . " 2>&1";
        exec($cmd, $output, $rc);
        @unlink($c_path);

        if ($rc !== 0) {
            @unlink($so_path);
            throw new \RuntimeException(
                "Failed to compile CAS hash map: " . implode("\n", $output)
            );
        }

        return $so_path;
    }
}
