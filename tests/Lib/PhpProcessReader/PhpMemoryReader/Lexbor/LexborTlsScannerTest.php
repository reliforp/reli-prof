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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\Lexbor;

use Reli\BaseTestCase;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\LexborBstCacheMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\LexborBstPoolMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\LexborMrawChunkMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\LexborUnicodeNormalizerBufferMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\MemoryLocations;
use Reli\Lib\Process\MemoryMap\ProcessMemoryArea;
use Reli\Lib\Process\MemoryMap\ProcessMemoryAttribute;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMap;

/**
 * Synthetic-byte coverage for the lexbor TLS structural fingerprinter.
 *
 * Builds a TLS image and small pieces of a fake target memory layout
 * that reproduce the byte patterns ext/uri's RINIT installs, then
 * verifies the scanner identifies and credits the four large LRUNs.
 */
final class LexborTlsScannerTest extends BaseTestCase
{
    /** Synthetic addresses chosen far apart so spurious matches are unlikely. */
    private const TLS_BASE     = 0x70000000_00000000;
    // Inside a fake [r-xp] segment used as the function-pointer landing pad.
    private const TEXT_FN1     = 0x4000_0000;
    private const TEXT_FN2     = 0x4000_0010;
    private const TEXT_BEGIN   = 0x4000_0000;
    private const TEXT_END     = 0x4001_0000;

    // Heap-side fake addresses (page-aligned).
    private const NORMALIZER_BUF = 0x10000_0000;
    private const MRAW_CHUNK_DATA = 0x20000_0000;
    private const BST_POOL_DATA  = 0x30000_0000;
    private const BST_CACHE_LIST = 0x40000_0000;

    // Pointers to the (fake) sub-structs the scanner walks.
    private const MRAW_MEM       = 0x50000_0000;
    private const MRAW_CHUNK     = 0x50001_0000;
    private const MRAW_CACHE_BST = 0x50002_0000;
    private const BST_DOBJECT    = 0x50003_0000;
    private const BST_DMEM       = 0x50004_0000;
    private const BST_DMEM_CHUNK = 0x50005_0000;
    private const BST_CACHE_ARR  = 0x50006_0000;

    public function testScannerEmitsAllFourLocationsOnAValidTlsImage(): void
    {
        $tls = $this->buildSyntheticTlsImage();
        $reader = new LexborTestMemoryReader();
        $reader->preload(self::TLS_BASE, $tls);
        $this->preloadHeap($reader);

        $process_memory_map = $this->buildProcessMemoryMap();
        $memory_locations = new MemoryLocations();
        $scanner = new LexborTlsScanner($reader);

        $emitted = $scanner->scan(
            pid: 1234,
            tls_block_address: self::TLS_BASE,
            tls_block_size: strlen($tls),
            process_memory_map: $process_memory_map,
            memory_locations: $memory_locations,
        );

        $this->assertSame(4, $emitted, 'expected 4 lexbor locations');

        $byClass = [];
        foreach ($memory_locations->memory_locations as $loc) {
            $byClass[$loc::class] = $loc;
        }
        $this->assertArrayHasKey(LexborUnicodeNormalizerBufferMemoryLocation::class, $byClass);
        $this->assertArrayHasKey(LexborMrawChunkMemoryLocation::class, $byClass);
        $this->assertArrayHasKey(LexborBstPoolMemoryLocation::class, $byClass);
        $this->assertArrayHasKey(LexborBstCacheMemoryLocation::class, $byClass);

        $this->assertSame(
            32768,
            $byClass[LexborUnicodeNormalizerBufferMemoryLocation::class]->size,
        );
        $this->assertSame(
            self::NORMALIZER_BUF,
            $byClass[LexborUnicodeNormalizerBufferMemoryLocation::class]->address,
        );
        $this->assertSame(
            self::MRAW_CHUNK_DATA,
            $byClass[LexborMrawChunkMemoryLocation::class]->address,
        );
        $this->assertSame(
            12288,
            $byClass[LexborMrawChunkMemoryLocation::class]->size,
        );
        $this->assertSame(
            self::BST_POOL_DATA,
            $byClass[LexborBstPoolMemoryLocation::class]->address,
        );
        $this->assertSame(
            24576,
            $byClass[LexborBstPoolMemoryLocation::class]->size,
        );
        $this->assertSame(
            self::BST_CACHE_LIST,
            $byClass[LexborBstCacheMemoryLocation::class]->address,
        );
        $this->assertSame(
            4096,
            $byClass[LexborBstCacheMemoryLocation::class]->size,
        );
    }

    public function testScannerDoesNothingOnAnEmptyTlsImage(): void
    {
        $reader = new LexborTestMemoryReader();
        $reader->preload(self::TLS_BASE, str_repeat("\0", 1024));
        $process_memory_map = $this->buildProcessMemoryMap();
        $memory_locations = new MemoryLocations();
        $scanner = new LexborTlsScanner($reader);

        $emitted = $scanner->scan(
            pid: 1234,
            tls_block_address: self::TLS_BASE,
            tls_block_size: 1024,
            process_memory_map: $process_memory_map,
            memory_locations: $memory_locations,
        );

        $this->assertSame(0, $emitted);
        $this->assertSame([], $memory_locations->memory_locations);
    }

    public function testScannerSkipsNormalizerWhenInvariantsAreOff(): void
    {
        // Same TLS image but the `end - buf` invariant is broken: buf and end
        // sit 65536 bytes apart instead of 32768. We expect the normalizer
        // location to be dropped (mraw chain still credits 3 locations).
        $tls = $this->buildSyntheticTlsImage(normalizer_buf_size: 65536);
        $reader = new LexborTestMemoryReader();
        $reader->preload(self::TLS_BASE, $tls);
        $this->preloadHeap($reader);

        $process_memory_map = $this->buildProcessMemoryMap();
        $memory_locations = new MemoryLocations();
        $scanner = new LexborTlsScanner($reader);

        $emitted = $scanner->scan(
            pid: 1234,
            tls_block_address: self::TLS_BASE,
            tls_block_size: strlen($tls),
            process_memory_map: $process_memory_map,
            memory_locations: $memory_locations,
        );

        // mraw_chunk + bst_pool + bst_cache, but no normalizer.
        $this->assertSame(3, $emitted);
        $this->assertFalse(
            $memory_locations->has(self::NORMALIZER_BUF),
            'normalizer should NOT be credited when buffer-size invariant fails',
        );
    }

    public function testScannerSkipsMrawWhenStructSizeIsWrong(): void
    {
        // The BST `struct_size` is supposed to be 48 (sizeof(bst_entry)).
        // If a future lexbor revision changed this we'd want to fail closed.
        $tls = $this->buildSyntheticTlsImage();
        $reader = new LexborTestMemoryReader();
        $reader->preload(self::TLS_BASE, $tls);
        $this->preloadHeap($reader, bst_struct_size: 64);

        $process_memory_map = $this->buildProcessMemoryMap();
        $memory_locations = new MemoryLocations();
        $scanner = new LexborTlsScanner($reader);

        $emitted = $scanner->scan(
            pid: 1234,
            tls_block_address: self::TLS_BASE,
            tls_block_size: strlen($tls),
            process_memory_map: $process_memory_map,
            memory_locations: $memory_locations,
        );

        // normalizer + mraw_chunk; the BST chain bails on the wrong struct_size.
        $this->assertSame(2, $emitted);
        $this->assertFalse($memory_locations->has(self::BST_POOL_DATA));
        $this->assertFalse($memory_locations->has(self::BST_CACHE_LIST));
    }

    /**
     * Build a synthetic PT_TLS image holding:
     *   - a 32-byte filler at offset 0,
     *   - the 88-byte `lxb_unicode_normalizer_t`-shaped block at offset 32,
     *   - 32 bytes of filler,
     *   - the 24-byte `lexbor_mraw_t`-shaped block.
     */
    private function buildSyntheticTlsImage(int $normalizer_buf_size = 32768): string
    {
        $tls = str_repeat("\0", 32);

        // lxb_unicode_normalizer_t (the mock keeps unused fields zero).
        $normalizer = pack('P', self::TEXT_FN1);                                   // decomposition
        $normalizer .= pack('P', self::TEXT_FN2);                                  // composition
        $normalizer .= pack('C', 0x06) . str_repeat("\0", 7);                      // quick_type + pad
        $normalizer .= pack('P', 0);                                               // starter
        $normalizer .= pack('P', 0);                                               // tmp_lenght
        $normalizer .= pack('P', self::NORMALIZER_BUF);                            // buf
        $normalizer .= pack('P', self::NORMALIZER_BUF + $normalizer_buf_size);     // end
        $normalizer .= pack('P', self::NORMALIZER_BUF);                            // p
        $normalizer .= pack('P', self::NORMALIZER_BUF);                            // ican
        $normalizer .= pack('C', 0) . str_repeat("\0", 3);                         // quick_ccc + pad
        $normalizer .= pack('V', 1024);                                            // flush_cp
        // sizeof(lxb_unicode_normalizer_t) is 80 — no trailing pad.
        $tls .= $normalizer;

        // 32 bytes of filler, then lexbor_mraw_t.
        $tls .= str_repeat("\0", 32);
        $tls .= pack('P', self::MRAW_MEM);        // mem
        $tls .= pack('P', self::MRAW_CACHE_BST);  // cache
        $tls .= pack('P', 0);                     // ref_count

        // Round to 8 KB so the read loop's chunk size kicks in deterministically.
        $tls .= str_repeat("\0", 8 * 1024 - strlen($tls));
        return $tls;
    }

    /** Populate the fake target memory used by the scanner's pointer chase. */
    private function preloadHeap(
        LexborTestMemoryReader $reader,
        int $bst_struct_size = 48,
    ): void {
        // lexbor_mem_t for mraw.
        $reader->preload(
            self::MRAW_MEM,
            pack('P4', self::MRAW_CHUNK, self::MRAW_CHUNK, 8192, 1),
        );
        // lexbor_mem_chunk_t for mraw.
        $reader->preload(
            self::MRAW_CHUNK,
            pack('P5', self::MRAW_CHUNK_DATA, 0, 12288, 0, 0),
        );

        // lexbor_bst_t for mraw.cache.
        $reader->preload(
            self::MRAW_CACHE_BST,
            pack('P3', self::BST_DOBJECT, 0, 0),
        );

        // lexbor_dobject_t.
        $reader->preload(
            self::BST_DOBJECT,
            pack('P4', self::BST_DMEM, self::BST_CACHE_ARR, 0, $bst_struct_size),
        );

        // lexbor_mem_t for the dobject's pool.
        $reader->preload(
            self::BST_DMEM,
            pack('P4', self::BST_DMEM_CHUNK, self::BST_DMEM_CHUNK, 24576, 1),
        );
        // lexbor_mem_chunk_t for the dobject's pool.
        $reader->preload(
            self::BST_DMEM_CHUNK,
            pack('P5', self::BST_POOL_DATA, 0, 24576, 0, 0),
        );
        // lexbor_array_t for the dobject's cache.
        $reader->preload(
            self::BST_CACHE_ARR,
            pack('P3', self::BST_CACHE_LIST, 512, 0),
        );
    }

    private function buildProcessMemoryMap(): ProcessMemoryMap
    {
        $rx_attr = new ProcessMemoryAttribute(read: true, write: false, execute: true, protected: false);
        return new ProcessMemoryMap([
            new ProcessMemoryArea(
                begin: dechex(self::TEXT_BEGIN),
                end: dechex(self::TEXT_END),
                file_offset: '0',
                attribute: $rx_attr,
                device_id: '00:00',
                inode_num: 0,
                name: '/usr/bin/php',
            ),
        ]);
    }
}
