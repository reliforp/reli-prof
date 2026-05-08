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
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendMmChunkMemoryLocation;
use Reli\Lib\Process\MemoryLocation;
use Reli\Lib\Process\MemoryMap\ProcessMemoryArea;
use Reli\Lib\Process\MemoryMap\ProcessMemoryAttribute;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMap;

/**
 * Synthetic-byte coverage for the lexbor structural fingerprinter.
 *
 * Builds a memory image (NTS BSS- or ZTS PT_TLS-shaped, the scanner
 * doesn't care which the bytes came from) and small pieces of a fake
 * target memory layout that reproduce the byte patterns ext/uri's
 * RINIT installs, then verifies the scanner identifies and credits
 * the four large LRUNs.
 */
final class LexborStateScannerTest extends BaseTestCase
{
    /** Synthetic addresses chosen far apart so spurious matches are unlikely. */
    private const IMAGE_BASE   = 0x70000000_00000000;
    // Inside a fake [r-xp] segment used as the function-pointer landing pad.
    private const TEXT_FN1     = 0x4000_0000;
    private const TEXT_FN2     = 0x4000_0010;
    private const TEXT_BEGIN   = 0x4000_0000;
    private const TEXT_END     = 0x4001_0000;

    // Heap-side fake addresses — placed inside a synthetic ZendMM chunk
    // so the containment check passes.
    private const ZENDMM_CHUNK_BASE = 0x10000_0000;
    private const ZENDMM_CHUNK_SIZE = 0x200000;          // 2 MiB
    private const NORMALIZER_BUF    = 0x10000_0000 + 0x10000;
    private const MRAW_CHUNK_DATA   = 0x10000_0000 + 0x20000;
    private const BST_POOL_DATA     = 0x10000_0000 + 0x40000;
    private const BST_CACHE_LIST    = 0x10000_0000 + 0x60000;

    // Pointers to the (fake) sub-structs the scanner walks. Outside
    // the chunk because they're glibc-heap allocations.
    private const MRAW_MEM       = 0x50000_0000;
    private const MRAW_CHUNK     = 0x50001_0000;
    private const MRAW_CACHE_BST = 0x50002_0000;
    private const BST_DOBJECT    = 0x50003_0000;
    private const BST_DMEM       = 0x50004_0000;
    private const BST_DMEM_CHUNK = 0x50005_0000;
    private const BST_CACHE_ARR  = 0x50006_0000;

    /**
     * `lexbor_mem_chunk_t::size` for a default `lexbor_mraw_init(8192)`:
     * `chunk_size + lexbor_mraw_meta_size()` aligned to the 8-byte step
     * `lexbor_mem_align` rounds up to. The exact value was 8200 on the
     * live `php:8.5-cli` we developed against; we use it here so the
     * test reflects the real struct field rather than the ZendMM page-
     * rounded LRUN size (the LRUN rounding is the analyzer's concern,
     * not the scanner's).
     */
    private const MRAW_CHUNK_RECORDED_SIZE = 8200;

    public function testScannerEmitsAllFourLocationsOnAValidImage(): void
    {
        $image = $this->buildSyntheticImage();
        $reader = new LexborTestMemoryReader();
        $reader->preload(self::IMAGE_BASE, $image);
        $this->preloadHeap($reader);

        $process_memory_map = $this->buildProcessMemoryMap();
        $memory_locations = new MemoryLocations();
        $known = $this->buildKnownZendMmLocations();
        $scanner = new LexborStateScanner($reader);

        $emitted = $scanner->scan(
            pid: 1234,
            ranges: [[self::IMAGE_BASE, strlen($image)]],
            process_memory_map: $process_memory_map,
            memory_locations: $memory_locations,
            known_zendmm_locations: $known,
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
        // Reflects `lexbor_mem_chunk_t::size` directly, not the ZendMM
        // LRUN-rounded size (which is what the page-level analyzer reports).
        $this->assertSame(
            self::MRAW_CHUNK_RECORDED_SIZE,
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

    public function testScannerDoesNothingOnAnEmptyImage(): void
    {
        $reader = new LexborTestMemoryReader();
        $reader->preload(self::IMAGE_BASE, str_repeat("\0", 1024));
        $process_memory_map = $this->buildProcessMemoryMap();
        $memory_locations = new MemoryLocations();
        $scanner = new LexborStateScanner($reader);

        $emitted = $scanner->scan(
            pid: 1234,
            ranges: [[self::IMAGE_BASE, 1024]],
            process_memory_map: $process_memory_map,
            memory_locations: $memory_locations,
            known_zendmm_locations: $this->buildKnownZendMmLocations(),
        );

        $this->assertSame(0, $emitted);
        $this->assertSame([], $memory_locations->memory_locations);
    }

    public function testScannerSkipsNormalizerWhenInvariantsAreOff(): void
    {
        // Same image but the `end - buf` invariant is broken: buf and end
        // sit 65536 bytes apart instead of 32768. We expect the normalizer
        // location to be dropped (mraw chain still credits 3 locations).
        $image = $this->buildSyntheticImage(normalizer_buf_size: 65536);
        $reader = new LexborTestMemoryReader();
        $reader->preload(self::IMAGE_BASE, $image);
        $this->preloadHeap($reader);

        $process_memory_map = $this->buildProcessMemoryMap();
        $memory_locations = new MemoryLocations();
        $scanner = new LexborStateScanner($reader);

        $emitted = $scanner->scan(
            pid: 1234,
            ranges: [[self::IMAGE_BASE, strlen($image)]],
            process_memory_map: $process_memory_map,
            memory_locations: $memory_locations,
            known_zendmm_locations: $this->buildKnownZendMmLocations(),
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
        $image = $this->buildSyntheticImage();
        $reader = new LexborTestMemoryReader();
        $reader->preload(self::IMAGE_BASE, $image);
        $this->preloadHeap($reader, bst_struct_size: 64);

        $process_memory_map = $this->buildProcessMemoryMap();
        $memory_locations = new MemoryLocations();
        $scanner = new LexborStateScanner($reader);

        $emitted = $scanner->scan(
            pid: 1234,
            ranges: [[self::IMAGE_BASE, strlen($image)]],
            process_memory_map: $process_memory_map,
            memory_locations: $memory_locations,
            known_zendmm_locations: $this->buildKnownZendMmLocations(),
        );

        // normalizer + mraw_chunk; the BST chain bails on the wrong struct_size.
        $this->assertSame(2, $emitted);
        $this->assertFalse($memory_locations->has(self::BST_POOL_DATA));
        $this->assertFalse($memory_locations->has(self::BST_CACHE_LIST));
    }

    public function testScannerRejectsMatchesOutsideKnownZendMmLocations(): void
    {
        // Same fingerprint, but the buffers don't land inside the known
        // ZendMM chunk we tell the scanner about. A spurious mraw / BST /
        // normalizer pattern that survives the byte-level fingerprint
        // must still be rejected when the resulting buffer pointer
        // doesn't sit inside a chunk / huge alloc the rest of the
        // analyzer believes in.
        $image = $this->buildSyntheticImage();
        $reader = new LexborTestMemoryReader();
        $reader->preload(self::IMAGE_BASE, $image);
        $this->preloadHeap($reader);

        $process_memory_map = $this->buildProcessMemoryMap();
        $memory_locations = new MemoryLocations();
        // An unrelated chunk, far away from the synthetic buffer addresses.
        $unrelated_chunk = new MemoryLocations();
        $unrelated_chunk->add(new ZendMmChunkMemoryLocation(
            0x1234567_0000,
            0x200000,
        ));
        $scanner = new LexborStateScanner($reader);

        $emitted = $scanner->scan(
            pid: 1234,
            ranges: [[self::IMAGE_BASE, strlen($image)]],
            process_memory_map: $process_memory_map,
            memory_locations: $memory_locations,
            known_zendmm_locations: [$unrelated_chunk],
        );

        $this->assertSame(0, $emitted);
        $this->assertSame([], $memory_locations->memory_locations);
    }

    public function testScannerOptsOutOfContainmentCheckWhenNoKnownLocationsGiven(): void
    {
        // Back-compat path: callers that don't have authoritative ZendMM
        // location sets can still opt out by passing `[]`. The four
        // structures should still be credited even though no chunk is
        // declared known.
        $image = $this->buildSyntheticImage();
        $reader = new LexborTestMemoryReader();
        $reader->preload(self::IMAGE_BASE, $image);
        $this->preloadHeap($reader);

        $process_memory_map = $this->buildProcessMemoryMap();
        $memory_locations = new MemoryLocations();
        $scanner = new LexborStateScanner($reader);

        $emitted = $scanner->scan(
            pid: 1234,
            ranges: [[self::IMAGE_BASE, strlen($image)]],
            process_memory_map: $process_memory_map,
            memory_locations: $memory_locations,
            known_zendmm_locations: [],
        );

        $this->assertSame(4, $emitted);
    }

    /**
     * Build a synthetic image holding:
     *   - a 32-byte filler at offset 0,
     *   - the 72-byte `lxb_unicode_normalizer_t`-shaped block at offset 32
     *     (upstream layout — see v85.h),
     *   - 32 bytes of filler,
     *   - the 24-byte `lexbor_mraw_t`-shaped block.
     */
    private function buildSyntheticImage(int $normalizer_buf_size = 32768): string
    {
        $image = str_repeat("\0", 32);

        // lxb_unicode_normalizer_t (upstream order; see v85.h):
        $normalizer  = pack('P', self::TEXT_FN1);                              // 0  decomposition
        $normalizer .= pack('P', self::TEXT_FN2);                              // 8  composition
        $normalizer .= pack('P', 0);                                           // 16 starter (NULL idle)
        $normalizer .= pack('P', self::NORMALIZER_BUF);                        // 24 buf
        $normalizer .= pack('P', self::NORMALIZER_BUF + $normalizer_buf_size); // 32 end
        $normalizer .= pack('P', self::NORMALIZER_BUF);                        // 40 p
        $normalizer .= pack('P', self::NORMALIZER_BUF);                        // 48 ican
        $normalizer .= str_repeat("\0", 4);                                    // 56 tmp[4]
        $normalizer .= pack('C', 0);                                           // 60 tmp_lenght
        $normalizer .= pack('C', 0);                                           // 61 quick_ccc
        // 62 quick_type: NFC = NFC_NO | NFC_MAYBE = 0x02 | 0x01 = 0x03
        $normalizer .= pack('C', 0x03);
        $normalizer .= "\0";                                                   // 63 pad
        $normalizer .= pack('P', 1024);                                        // 64 flush_cp (size_t)
        // sizeof = 72.
        $image .= $normalizer;

        // 32 bytes of filler, then lexbor_mraw_t.
        $image .= str_repeat("\0", 32);
        $image .= pack('P', self::MRAW_MEM);        // mem
        $image .= pack('P', self::MRAW_CACHE_BST);  // cache
        $image .= pack('P', 0);                     // ref_count

        // Round to 8 KB so the read loop's chunk size kicks in deterministically.
        $image .= str_repeat("\0", 8 * 1024 - strlen($image));
        return $image;
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
        // lexbor_mem_chunk_t for mraw — `size` field reflects what real
        // lexbor records (`chunk_size + meta_size`, not the LRUN page
        // rounding the ZendMM-side sees).
        $reader->preload(
            self::MRAW_CHUNK,
            pack('P5', self::MRAW_CHUNK_DATA, 0, self::MRAW_CHUNK_RECORDED_SIZE, 0, 0),
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

    /**
     * Build a `MemoryLocations` set with one synthetic ZendMM chunk that
     * covers the four heap-side fake addresses, mirroring how the
     * collector hands its real `chunk_memory_locations` set in.
     *
     * @return list<MemoryLocations>
     */
    private function buildKnownZendMmLocations(): array
    {
        $chunks = new MemoryLocations();
        $chunks->add(new ZendMmChunkMemoryLocation(
            self::ZENDMM_CHUNK_BASE,
            self::ZENDMM_CHUNK_SIZE,
        ));
        return [$chunks];
    }
}
