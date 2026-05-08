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

use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\LexborBstCacheMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\LexborBstPoolMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\LexborMrawChunkMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\LexborUnicodeNormalizerBufferMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\MemoryLocations;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\LexborStateContext;
use Reli\Lib\Process\MemoryLocation;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMap;
use Reli\Lib\Process\MemoryReader\MemoryReaderException;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;

/**
 * Structural fingerprinter for the four lexbor state variables that
 * ext/uri's `PHP_RINIT_FUNCTION(uri_parser_whatwg)` installs on PHP >= 8.5:
 *
 *   - lexbor_mraw   (mraw memory pool — initial chunk plus a 512-entry
 *                    BST and a 512-slot cache list)
 *   - lexbor_parser (no extra heap on idle workers, scanned indirectly
 *                    through `lexbor_mraw`)
 *   - lexbor_idna   (Unicode NFC normalizer with a 32 KB scratch buffer)
 *
 * The variables are declared `ZEND_TLS`. On NTS builds (the 99 % case
 * for distro PHP CLI / FPM / mod_php) `ZEND_TLS` expands to plain
 * `static`, so the variables live in the main binary's `.bss`. On ZTS
 * builds it expands to `static __thread` and they live in PT_TLS. Both
 * symbols are file-static, so they are not in `.dynsym`, and `.symtab`
 * is gone on stripped distro builds — name-based lookup is unavailable.
 *
 * The scanner walks each memory range it is given at 8-byte alignment,
 * looking for a `lxb_unicode_normalizer_t`-shaped window first (the
 * normalizer has the strongest invariants), then a `lexbor_mraw_t`-
 * shaped window with chained dereferences. The caller is responsible
 * for choosing the right ranges:
 *
 *   NTS: the binary's `rw-p` VMA + the immediately following anon
 *        mapping that holds the BSS tail.
 *   ZTS: the live thread's PT_TLS image.
 *
 * Conservatively: when invariants don't hold, no credit is given. We
 * prefer an honest `coverage_gap` to a false credit that would dilute
 * the `analyzed_percentage` metric.
 */
final class LexborStateScanner
{
    /**
     * `lxb_unicode_normalizer_init` always allocates 4096 buffer entries,
     * each `sizeof(lxb_unicode_buffer_t) == 8` (one codepoint slot).
     * `end - buf == 32768` is one of the strongest invariants in the
     * fingerprint set.
     */
    private const NORMALIZER_BUFFER_BYTES = 32768;

    /**
     * `lxb_unicode_quick_type_t LXB_UNICODE_QUICK_NFC_NO | LXB_UNICODE_QUICK_NFC_MAYBE`
     * — the value `lxb_unicode_idna_init` writes into `quick_type` for
     * NFC-mode normalization. The enum (php-8.5.5/ext/lexbor/lexbor/
     * unicode/base.h) defines NFC_MAYBE = 1<<0 = 0x01 and NFC_NO = 1<<1
     * = 0x02, so the OR is 0x03. NFD/NFKC/NFKD use different bit
     * patterns, so this single byte rules them out.
     */
    private const NORMALIZER_QUICK_TYPE_NFC = 0x03;

    /**
     * `LXB_UNICODE_FLUSH_CP` — the constant ext/uri's lexbor build sets
     * for the scratchpad codepoint marker. Stable across lexbor versions
     * for the foreseeable future.
     */
    private const NORMALIZER_FLUSH_CP = 1024;

    /**
     * Window size in bytes when scanning for a `lxb_unicode_normalizer_t`
     * fingerprint. Upstream layout (see v85.h) is 72 bytes:
     *   off  0: decomposition (8B fn ptr)
     *   off  8: composition  (8B fn ptr)
     *   off 16: starter      (8B ptr; NULL at idle)
     *   off 24: buf          (8B ptr; ZendMM page-aligned)
     *   off 32: end          (8B ptr; buf + 32768)
     *   off 40: p            (8B ptr; == buf at idle)
     *   off 48: ican         (8B ptr; == buf at idle)
     *   off 56: tmp[4]       (4B)
     *   off 60: tmp_lenght   (1B; 0 at idle)
     *   off 61: quick_ccc    (1B; 0 at idle)
     *   off 62: quick_type   (1B; 0x03 for NFC)
     *   off 63: pad          (1B)
     *   off 64: flush_cp     (8B size_t; 1024 at init)
     */
    private const NORMALIZER_WINDOW_BYTES = 72;

    /**
     * `lexbor_mraw_init` is called with `chunk_size = 8192`; the
     * downstream `lexbor_mem_init` stores `chunk_size + meta_size`
     * (a small constant) as `chunk_min_size`. We accept a band so we
     * survive minor lexbor revisions, while keeping the lower bound
     * far below the BST pool's 24 KiB so the two cases don't collide.
     */
    private const MRAW_CHUNK_MIN_SIZE_LOW = 4096;
    private const MRAW_CHUNK_MIN_SIZE_HIGH = 16384;

    /**
     * `lexbor_bst_init`'s `lexbor_dobject_init` chunk size:
     * `512 * sizeof(lexbor_bst_entry_t) == 512 * 48 == 24576`.
     */
    private const BST_POOL_MIN_SIZE = 24576;

    /**
     * `lexbor_dobject_init`'s `struct_size` for a BST: 48 (sizeof of one
     * `lexbor_bst_entry_t`).
     */
    private const BST_DOBJECT_STRUCT_SIZE = 48;

    /**
     * `lexbor_bst_init` uses chunk size 512 → cache list `size == 512`.
     */
    private const BST_CACHE_SIZE = 512;

    /**
     * Cached `[r-xp]` ranges from the process memory map. Each entry is a
     * `[begin, end)` pair of inclusive-exclusive virtual addresses.
     *
     * @var list<array{0: int, 1: int}>
     */
    private array $text_segments = [];

    private bool $text_segments_built = false;

    public function __construct(
        private MemoryReaderInterface $memory_reader,
    ) {
    }

    /**
     * Scan one or more memory ranges for lexbor struct fingerprints and
     * emit MemoryLocations for matches.
     *
     * `$ranges` is a list of `[start_address, size_in_bytes]` pairs.
     * The caller is responsible for passing the right ranges (see the
     * class docblock for NTS / ZTS guidance).
     *
     * `$known_zendmm_locations` is an optional list of `MemoryLocations`
     * the scanner should treat as authoritative homes for emitted
     * locations: a candidate buffer pointer that does not land inside
     * one of these is rejected. Pass the chunk and huge-list location
     * sets here to make sure spurious pointer chases can't credit a
     * region the rest of the analyzer doesn't even know about.
     *
     * Returns the number of locations added. Any read or parse failure
     * is swallowed so the caller can run this opportunistically on every
     * collect without breaking the analyze pass.
     *
     * @param list<array{int, int}>  $ranges
     * @param list<MemoryLocations>  $known_zendmm_locations
     */
    public function scan(
        int $pid,
        array $ranges,
        ProcessMemoryMap $process_memory_map,
        MemoryLocations $memory_locations,
        ?LexborStateContext $state_context = null,
        array $known_zendmm_locations = [],
    ): int {
        $this->indexExecutableSegments($process_memory_map);

        $emitted = 0;
        foreach ($ranges as [$address, $size]) {
            if ($size <= 0 || $size > 8 * 1024 * 1024) {
                // Sanity: BSS / TLS images are typically a few KB to a few
                // hundred KB. >8 MB is implausible and almost certainly a
                // misread that would make the linear scan take forever.
                continue;
            }
            try {
                $image = $this->readImage($pid, $address, $size);
            } catch (MemoryReaderException) {
                continue;
            }
            $emitted += $this->scanForNormalizer(
                $image,
                $memory_locations,
                $state_context,
                $known_zendmm_locations,
            );
            $emitted += $this->scanForMraw(
                $pid,
                $image,
                $memory_locations,
                $state_context,
                $known_zendmm_locations,
            );
        }
        return $emitted;
    }

    /**
     * Read a memory image as a binary string. We read in chunks of
     * 64 KB to keep individual `process_vm_readv` calls bounded and to
     * avoid pathological behaviour if the kernel returns a partial read
     * around an unmapped page boundary.
     *
     * @throws MemoryReaderException
     */
    private function readImage(int $pid, int $address, int $size): string
    {
        $chunk_size = 65536;
        $buffer = '';
        for ($offset = 0; $offset < $size; $offset += $chunk_size) {
            $remaining = $size - $offset;
            $request = $remaining > $chunk_size ? $chunk_size : $remaining;
            $cdata = $this->memory_reader->read($pid, $address + $offset, $request);
            $buffer .= \FFI::string($cdata, $request);
        }
        return $buffer;
    }

    /** Cache (begin, end) ranges of all `[r-xp]` (executable) memory areas. */
    private function indexExecutableSegments(ProcessMemoryMap $process_memory_map): void
    {
        if ($this->text_segments_built) {
            return;
        }
        $segments = [];
        foreach ($process_memory_map->findByNameRegex('.*') as $area) {
            if (!$area->attribute->execute || !$area->attribute->read) {
                continue;
            }
            $begin = (int)hexdec($area->begin);
            $end = (int)hexdec($area->end);
            if ($end > $begin) {
                $segments[] = [$begin, $end];
            }
        }
        $this->text_segments = $segments;
        $this->text_segments_built = true;
    }

    /** True if `$address` falls inside any indexed `[r-xp]` segment. */
    private function isInExecutableSegment(int $address): bool
    {
        foreach ($this->text_segments as $range) {
            if ($address >= $range[0] && $address < $range[1]) {
                return true;
            }
        }
        return false;
    }

    /**
     * True if the candidate location is fully contained by at least one
     * of the supplied authoritative ZendMM location sets, or if the
     * caller passed no sets at all (back-compat: tests can opt out by
     * passing `[]`).
     *
     * @param list<MemoryLocations> $known_zendmm_locations
     */
    private function isInsideKnownZendMm(
        MemoryLocation $candidate,
        array $known_zendmm_locations,
    ): bool {
        if ($known_zendmm_locations === []) {
            return true;
        }
        foreach ($known_zendmm_locations as $set) {
            if ($set->getContainingMemoryLocation($candidate) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * Walk the image at 8-byte alignment looking for the
     * `lxb_unicode_normalizer_t` fingerprint:
     *
     *   end - buf == 32768  AND
     *   p == buf            AND
     *   ican == buf         AND
     *   quick_type == 0x03  AND
     *   flush_cp == 1024    AND
     *   decomposition + composition both land in `[r-xp]`
     *
     * On match (and only when the resulting `[buf, end)` falls inside a
     * known ZendMM chunk / huge alloc), emit a
     * `LexborUnicodeNormalizerBufferMemoryLocation` over `[buf, end)`.
     *
     * @param list<MemoryLocations> $known_zendmm_locations
     */
    private function scanForNormalizer(
        string $image,
        MemoryLocations $memory_locations,
        ?LexborStateContext $state_context,
        array $known_zendmm_locations,
    ): int {
        $emitted = 0;
        $size = strlen($image);
        $end = $size - self::NORMALIZER_WINDOW_BYTES;
        for ($offset = 0; $offset <= $end; $offset += 8) {
            /** @var array{1: int, 2: int, 3: int, 4: int, 5: int, 6: int, 7: int}|false $u */
            $u = unpack('P7', $image, $offset);
            if ($u === false) {
                break;
            }
            $decomposition = $u[1];  // offset  0
            $composition   = $u[2];  // offset  8
            $starter       = $u[3];  // offset 16; NULL at idle
            $buf           = $u[4];  // offset 24
            $end_ptr       = $u[5];  // offset 32
            $p             = $u[6];  // offset 40
            $ican          = $u[7];  // offset 48
            if ($decomposition === 0 || $composition === 0) {
                continue;
            }
            if ($starter !== 0) {
                continue;
            }
            if ($buf === 0 || $end_ptr === 0) {
                continue;
            }
            if (($end_ptr - $buf) !== self::NORMALIZER_BUFFER_BYTES) {
                continue;
            }
            // ZendMM aligns LRUNs to a page (4096); buf must land there.
            if (($buf & 0xfff) !== 0) {
                continue;
            }
            if ($p !== $buf || $ican !== $buf) {
                continue;
            }
            // tmp_lenght / quick_ccc / quick_type live in the byte triplet at
            // offsets 60, 61, 62 (right after tmp[4]). All three are zero at
            // idle except quick_type, which is fixed to 0x03 by the NFC init.
            if (
                $image[$offset + 60] !== "\x00"
                || $image[$offset + 61] !== "\x00"
            ) {
                continue;
            }
            if (ord($image[$offset + 62]) !== self::NORMALIZER_QUICK_TYPE_NFC) {
                continue;
            }
            // flush_cp is size_t (8B), set to 1024 by lxb_unicode_normalizer_init.
            /** @var array{1: int}|false $fc_u */
            $fc_u = unpack('P', $image, $offset + 64);
            if ($fc_u === false) {
                continue;
            }
            if ($fc_u[1] !== self::NORMALIZER_FLUSH_CP) {
                continue;
            }
            if (
                !$this->isInExecutableSegment($decomposition)
                || !$this->isInExecutableSegment($composition)
            ) {
                continue;
            }
            $location = new LexborUnicodeNormalizerBufferMemoryLocation(
                $buf,
                self::NORMALIZER_BUFFER_BYTES,
            );
            if (!$this->isInsideKnownZendMm($location, $known_zendmm_locations)) {
                continue;
            }
            if (!$memory_locations->has($buf)) {
                $memory_locations->add($location);
                $state_context?->addLocation($location);
                $emitted++;
            }
            // There is exactly one normalizer per worker; don't waste cycles
            // on the rest of the image.
            break;
        }
        return $emitted;
    }

    /**
     * Walk the image at 8-byte alignment looking for the
     * `lexbor_mraw_t` fingerprint, then dereference into the heap to
     * credit the data buffer of `mem.chunk` and the BST pool / cache.
     *
     * `lexbor_mraw_t` itself is small and not very distinctive on its
     * own, so the match is gated on the chained validation: every
     * pointer must dereference to plausible bytes (page-aligned data
     * pointer, sane chunk_min_size, etc.) and every credited buffer
     * must land inside a known ZendMM chunk / huge allocation. When
     * any link fails we just move on; the `_t` is opaque enough that
     * occasional false starts are cheap.
     *
     * @param list<MemoryLocations> $known_zendmm_locations
     */
    private function scanForMraw(
        int $pid,
        string $image,
        MemoryLocations $memory_locations,
        ?LexborStateContext $state_context,
        array $known_zendmm_locations,
    ): int {
        $emitted = 0;
        $size = strlen($image);
        $window = 24; // sizeof(lexbor_mraw_t)
        $end = $size - $window;
        for ($offset = 0; $offset <= $end; $offset += 8) {
            /** @var array{1: int, 2: int, 3: int}|false $u */
            $u = unpack('P3', $image, $offset);
            if ($u === false) {
                break;
            }
            $mem_ptr = $u[1];
            $cache_ptr = $u[2];
            $ref_count = $u[3];
            if ($mem_ptr === 0 || $cache_ptr === 0) {
                continue;
            }
            if ($ref_count !== 0) {
                // RINIT-time idle workers always have ref_count == 0.
                continue;
            }
            try {
                $emitted += $this->tryFollowMraw(
                    $pid,
                    $mem_ptr,
                    $cache_ptr,
                    $memory_locations,
                    $state_context,
                    $known_zendmm_locations,
                );
            } catch (MemoryReaderException) {
                continue;
            }
        }
        return $emitted;
    }

    /**
     * @param list<MemoryLocations> $known_zendmm_locations
     * @throws MemoryReaderException
     */
    private function tryFollowMraw(
        int $pid,
        int $mem_ptr,
        int $cache_ptr,
        MemoryLocations $memory_locations,
        ?LexborStateContext $state_context,
        array $known_zendmm_locations,
    ): int {
        $emitted = 0;
        // lexbor_mem_t = { chunk, chunk_first, chunk_min_size, chunk_length }
        $mem_struct = $this->tryRead($pid, $mem_ptr, 32);
        if ($mem_struct === null) {
            return 0;
        }
        /** @var array{1: int, 2: int, 3: int, 4: int} $mu */
        $mu = unpack('P4', $mem_struct);
        $chunk_ptr = $mu[1];
        $chunk_first = $mu[2];
        $chunk_min_size = $mu[3];
        $chunk_length = $mu[4];
        if ($chunk_ptr === 0 || $chunk_first === 0) {
            return 0;
        }
        if (
            $chunk_min_size < self::MRAW_CHUNK_MIN_SIZE_LOW
            || $chunk_min_size > self::MRAW_CHUNK_MIN_SIZE_HIGH
        ) {
            return 0;
        }
        if ($chunk_length === 0 || $chunk_length > 1024) {
            return 0;
        }

        // lexbor_mem_chunk_t = { data, length, size, next, prev }
        $chunk_struct = $this->tryRead($pid, $chunk_ptr, 40);
        if ($chunk_struct === null) {
            return 0;
        }
        /** @var array{1: int, 2: int, 3: int, 4: int, 5: int} $cu */
        $cu = unpack('P5', $chunk_struct);
        $data_ptr = $cu[1];
        $data_size = $cu[3];
        if ($data_ptr === 0) {
            return 0;
        }
        // ZendMM page-aligned (4 KB) — the LRUN starts on a page boundary.
        if (($data_ptr & 0xfff) !== 0) {
            return 0;
        }
        if ($data_size < $chunk_min_size || $data_size > 16 * 1024 * 1024) {
            return 0;
        }

        $location = new LexborMrawChunkMemoryLocation($data_ptr, $data_size);
        if (!$this->isInsideKnownZendMm($location, $known_zendmm_locations)) {
            return 0;
        }
        if (!$memory_locations->has($data_ptr)) {
            $memory_locations->add($location);
            $state_context?->addLocation($location);
            $emitted++;
        }

        $emitted += $this->tryFollowBst(
            $pid,
            $cache_ptr,
            $memory_locations,
            $state_context,
            $known_zendmm_locations,
        );
        return $emitted;
    }

    /**
     * @param list<MemoryLocations> $known_zendmm_locations
     * @throws MemoryReaderException
     */
    private function tryFollowBst(
        int $pid,
        int $bst_ptr,
        MemoryLocations $memory_locations,
        ?LexborStateContext $state_context,
        array $known_zendmm_locations,
    ): int {
        // lexbor_bst_t = { dobject, root, tree_length }
        $bst = $this->tryRead($pid, $bst_ptr, 24);
        if ($bst === null) {
            return 0;
        }
        /** @var array{1: int, 2: int, 3: int} $bu */
        $bu = unpack('P3', $bst);
        $dobject_ptr = $bu[1];
        $root = $bu[2];
        $tree_length = $bu[3];
        if ($dobject_ptr === 0) {
            return 0;
        }
        if ($root !== 0 || $tree_length !== 0) {
            // Idle workers haven't inserted anything yet. A non-empty
            // BST tree means we're either looking at a different struct
            // or the worker has actually parsed URIs — either way, the
            // sizes below are no longer trustworthy.
            return 0;
        }

        // lexbor_dobject_t = { mem, cache, allocated, struct_size }
        $dobject = $this->tryRead($pid, $dobject_ptr, 32);
        if ($dobject === null) {
            return 0;
        }
        /** @var array{1: int, 2: int, 3: int, 4: int} $du */
        $du = unpack('P4', $dobject);
        $dobj_mem_ptr = $du[1];
        $dobj_cache_ptr = $du[2];
        $allocated = $du[3];
        $struct_size = $du[4];
        if ($dobj_mem_ptr === 0 || $dobj_cache_ptr === 0) {
            return 0;
        }
        if ($allocated !== 0) {
            return 0;
        }
        if ($struct_size !== self::BST_DOBJECT_STRUCT_SIZE) {
            return 0;
        }

        $emitted = 0;

        // lexbor_dobject_t.mem → lexbor_mem_t → mem_chunk → BST pool LRUN.
        $dmem = $this->tryRead($pid, $dobj_mem_ptr, 32);
        if ($dmem !== null) {
            /** @var array{1: int, 2: int, 3: int, 4: int} $dmu */
            $dmu = unpack('P4', $dmem);
            $dmem_chunk_ptr = $dmu[1];
            $dmem_min_size = $dmu[3];
            if (
                $dmem_chunk_ptr !== 0
                && $dmem_min_size >= self::BST_POOL_MIN_SIZE
                && $dmem_min_size <= 1024 * 1024
            ) {
                $dchunk = $this->tryRead($pid, $dmem_chunk_ptr, 40);
                if ($dchunk !== null) {
                    /** @var array{1: int, 2: int, 3: int, 4: int, 5: int} $dcu */
                    $dcu = unpack('P5', $dchunk);
                    $pool_data = $dcu[1];
                    $pool_size = $dcu[3];
                    if (
                        $pool_data !== 0
                        && ($pool_data & 0xfff) === 0
                        && $pool_size >= self::BST_POOL_MIN_SIZE
                        && $pool_size <= 1024 * 1024
                        && !$memory_locations->has($pool_data)
                    ) {
                        $location = new LexborBstPoolMemoryLocation($pool_data, $pool_size);
                        if ($this->isInsideKnownZendMm($location, $known_zendmm_locations)) {
                            $memory_locations->add($location);
                            $state_context?->addLocation($location);
                            $emitted++;
                        }
                    }
                }
            }
        }

        // lexbor_dobject_t.cache → lexbor_array_t → list array.
        $array = $this->tryRead($pid, $dobj_cache_ptr, 24);
        if ($array !== null) {
            /** @var array{1: int, 2: int, 3: int} $au */
            $au = unpack('P3', $array);
            $list_ptr = $au[1];
            $array_size = $au[2];
            $array_length = $au[3];
            if (
                $list_ptr !== 0
                && ($list_ptr & 0xfff) === 0
                && $array_size === self::BST_CACHE_SIZE
                && $array_length === 0
                && !$memory_locations->has($list_ptr)
            ) {
                $location = new LexborBstCacheMemoryLocation(
                    $list_ptr,
                    self::BST_CACHE_SIZE * 8,
                );
                if ($this->isInsideKnownZendMm($location, $known_zendmm_locations)) {
                    $memory_locations->add($location);
                    $state_context?->addLocation($location);
                    $emitted++;
                }
            }
        }

        return $emitted;
    }

    /**
     * Wrapper around `MemoryReader::read` that returns null on any
     * failure (rather than throwing). Used inside the chained pointer
     * walk so a single bad pointer doesn't abort the whole scan.
     */
    private function tryRead(int $pid, int $address, int $size): ?string
    {
        if ($address < 0x1000) {
            return null;
        }
        try {
            $cdata = $this->memory_reader->read($pid, $address, $size);
        } catch (MemoryReaderException) {
            return null;
        }
        return \FFI::string($cdata, $size);
    }
}
