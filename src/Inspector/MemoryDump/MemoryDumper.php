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

namespace Reli\Inspector\MemoryDump;

use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Inspector\Watch\RssReader;
use Reli\Lib\Log\Log;
use Reli\Lib\PhpInternals\Types\Zend\ZendCastedTypeProvider;
use Reli\Lib\PhpInternals\Types\Zend\ZendCompilerGlobals;
use Reli\Lib\PhpInternals\Types\Zend\ZendExecutorGlobals;
use Reli\Lib\PhpInternals\Types\Zend\ZendMmChunk;
use Reli\Lib\PhpInternals\VersionedPointedTypeResolver;
use Reli\Lib\PhpInternals\ZendTypeReaderCreator;
use Reli\Lib\PhpProcessReader\PhpZendMemoryManagerChunkFinder;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreatorInterface;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;
use Reli\Lib\Process\Pointer\Pointer;
use Reli\Lib\Process\Pointer\RemoteProcessDereferencer;
use Reli\Lib\Process\ProcessSpecifier;
use Reli\Lib\FFI\FFIHelper;

/**
 * Core memory dump logic extracted from MemoryDumpCommand.
 *
 * Enumerates ZendMM regions, bulk-reads via process_vm_readv,
 * and writes a binary dump file for offline analysis.
 */
final class MemoryDumper
{
    public function __construct(
        private MemoryReaderInterface $memory_reader,
        private ZendTypeReaderCreator $zend_type_reader_creator,
        private PhpZendMemoryManagerChunkFinder $chunk_finder,
        private ProcessMemoryMapCreatorInterface $process_memory_map_creator,
    ) {
    }

    /**
     * Perform a memory dump and write to file.
     *
     * @param TargetPhpSettings<'v70'|'v71'|'v72'|'v73'|'v74'|'v80'|'v81'|'v82'|'v83'|'v84'|'v85'> $target_php_settings
     * @param list<array{address: int, count: int}> $interned_string_arrays
     * @param \Closure|null $on_read_complete invoked once after every remote
     *     memory region has been read into local PHP strings, before the dump
     *     file is opened for writing. The sidecar uses this hook to
     *     `PTRACE_DETACH` the target right after the read phase, so the target
     *     can resume while the (potentially slow) disk write happens. Other
     *     callers can leave it null and pay the read+write window as one stop
     *     window. Typed as a `Closure` so the resume logic can capture the
     *     `$stopped` flag by reference.
     */
    public function dump(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings,
        int $eg_address,
        int $cg_address,
        string $output_path,
        bool $include_binary = false,
        bool $include_heap = false,
        array $interned_string_arrays = [],
        ?\Closure $on_read_complete = null,
    ): MemoryDumpResult {
        $php_version = $target_php_settings->php_version;
        $zend_type_reader = $this->zend_type_reader_creator->create(
            $php_version,
        );
        $dereferencer = new RemoteProcessDereferencer(
            $this->memory_reader,
            $process_specifier,
            new ZendCastedTypeProvider($zend_type_reader),
            new VersionedPointedTypeResolver($php_version),
        );

        $pid = $process_specifier->pid;
        // Capture RSS up-front so the figure embedded in the dump
        // reflects the target's footprint at snapshot time, not whatever
        // the PID happens to point at when the dump is replayed later.
        $rss_bytes = (new RssReader())->read($pid);
        $memory_map = $this->process_memory_map_creator
            ->getProcessMemoryMap($pid);

        // Locate ZendMM chunks
        $chunk_address = $this->chunk_finder->findAddress(
            $process_specifier,
            $target_php_settings,
            $eg_address,
            $dereferencer,
        );
        if ($chunk_address === null) {
            throw new \RuntimeException(
                'failed to find ZendMM main chunk',
            );
        }
        $main_chunk = $dereferencer->deref(new Pointer(
            ZendMmChunk::class,
            $chunk_address,
            $zend_type_reader->sizeOf('zend_mm_chunk'),
        ));

        // Phase 1: collect every candidate interval from every source
        // without pagemap filtering or deduplication. Sources frequently
        // overlap (ZendMM chunks are also visible as anonymous writable
        // mmap regions; the compiler arena usually lives inside a glibc
        // malloc mapping). We resolve the overlap in phase 2 by merging
        // into a disjoint interval list, then apply a single uniform
        // pagemap residency pass over the merged intervals so that the
        // residency filter cannot be bypassed by a second unfiltered
        // entry from a different source.
        /** @var list<array{address: int, size: int}> $intervals */
        $intervals = [];

        // EG + CG structs (usually inside the PHP binary RW segment, but
        // kept explicitly so we always cover the exact bytes we need).
        $intervals[] = [
            'address' => $eg_address,
            'size' => $zend_type_reader->sizeOf(
                'zend_executor_globals',
            ),
        ];
        $intervals[] = [
            'address' => $cg_address,
            'size' => $zend_type_reader->sizeOf(
                'zend_compiler_globals',
            ),
        ];

        // PHP BSS segment: the anonymous writable VMA that contains EG.
        // This covers EG, CG, zend_one_char_string[256], and other PHP
        // engine globals that live in the binary's .bss. Small (~130 KiB)
        // and always resident. Without this, globals like
        // zend_one_char_string are invisible in minimum mode because the
        // BSS is anonymous-writable (gated on --include-heap) and not
        // reachable from any walker root.
        $eg_vmas = $memory_map->findByAddress($eg_address);
        foreach ($eg_vmas as $vma) {
            if ($vma->attribute->write && $vma->attribute->read) {
                $addr = (int)hexdec($vma->begin);
                $size = (int)hexdec($vma->end) - $addr;
                if ($size > 0) {
                    $intervals[] = ['address' => $addr, 'size' => $size];
                }
            }
        }

        // ZendMM chunks
        foreach ($main_chunk->iterateChunks($dereferencer) as $chunk) {
            $intervals[] = [
                'address' => $chunk->getPointer()->address,
                'size' => ZendMmChunk::SIZE,
            ];
        }

        // Huge allocations
        $huge_list = $main_chunk->heap_slot->iterateHugeList(
            $dereferencer,
        );
        foreach ($huge_list as $huge) {
            $intervals[] = [
                'address' => $huge->ptr,
                'size' => $huge->size,
            ];
        }

        // [heap] region (glibc brk heap). In include_heap mode, the
        // entire resident range is captured for extension-state
        // retention analysis. In minimum mode, the heap is NOT bulk-
        // captured; a metadata peek walker runs later to selectively
        // read just the ~750 KiB of MINIT-time metadata that the
        // analyser needs.
        if ($include_heap) {
            $heap_areas = $memory_map->findByNameRegex('\\[heap\\]');
            foreach ($heap_areas as $area) {
                $addr = (int)hexdec($area->begin);
                $size = (int)hexdec($area->end) - $addr;
                if ($size > 0) {
                    $intervals[] = ['address' => $addr, 'size' => $size];
                }
            }
        }

        // Opcache SHM regions (e.g. /dev/zero mmap). When opcache is
        // enabled, interned strings and cached scripts live in shared
        // memory that can be very large (128-320MB+) but mostly empty.
        $shm_areas = $memory_map->findByNameRegex('/dev/zero');
        foreach ($shm_areas as $area) {
            if (!$area->attribute->read) {
                continue;
            }
            $addr = (int)hexdec($area->begin);
            $size = (int)hexdec($area->end) - $addr;
            if ($size > 0) {
                $intervals[] = ['address' => $addr, 'size' => $size];
            }
        }

        // Writable mmap regions. In include_heap mode, these are
        // captured for full coverage; in minimum mode they are
        // skipped because the ZendMM chunk / huge entries above
        // already cover the PHP-owned portion.
        //
        // Both anonymous (inode=0, empty name) and file-backed
        // (library .data/.bss segments) rw-p regions are included.
        // musl libc's dynamic linker donates unused page tails of
        // library writable segments to malloc (ldso/dynlink.c
        // reclaim_gaps), so persistent allocations like
        // EG(function_table) can land inside a library-named VMA.
        if ($include_heap) {
            $all_areas = $memory_map->findByNameRegex('.*');
            foreach ($all_areas as $area) {
                if (
                    $area->attribute->read
                    && $area->attribute->write
                    && !$area->attribute->execute
                ) {
                    $addr = (int)hexdec($area->begin);
                    $size = (int)hexdec($area->end) - $addr;
                    if ($size > 0) {
                        $intervals[] = ['address' => $addr, 'size' => $size];
                    }
                }
            }
        }

        // PHP binary's writable data/BSS segments
        $php_rw_areas = $memory_map->findByNameRegex(
            $target_php_settings->php_regex,
        );
        foreach ($php_rw_areas as $area) {
            if ($area->attribute->write && $area->attribute->read) {
                $addr = (int)hexdec($area->begin);
                $size = (int)hexdec($area->end) - $addr;
                if ($size > 0) {
                    $intervals[] = [
                        'address' => $addr,
                        'size' => $size,
                    ];
                }
            }
        }

        // VM stacks
        $eg = $dereferencer->deref(new Pointer(
            ZendExecutorGlobals::class,
            $eg_address,
            $zend_type_reader->sizeOf('zend_executor_globals'),
        ));
        if ($eg->vm_stack !== null) {
            $vm_stack = $dereferencer->deref($eg->vm_stack);
            foreach (
                $vm_stack->iterateStackChain($dereferencer) as $stack
            ) {
                if ($stack->end !== null) {
                    $addr = $stack->getPointer()->address;
                    $size = $stack->end->address - $addr;
                    if ($size > 0) {
                        $intervals[] = [
                            'address' => $addr,
                            'size' => $size,
                        ];
                    }
                }
            }
        }

        // EG(objects_store).object_buckets: the bucket array is
        // allocated via realloc() and may end up in an anonymous
        // writable mmap region outside ZendMM chunks. Without it,
        // EmitObjectsStoreJob fails and ALL object instances are lost.
        if ($eg->objects_store->object_buckets !== null) {
            $ob_addr = $eg->objects_store->object_buckets->address;
            $ob_size = $eg->objects_store->size * 8;
            if ($ob_size > 0) {
                $intervals[] = [
                    'address' => $ob_addr,
                    'size' => $ob_size,
                ];
            }
        }

        // Compiler arenas
        $cg = $dereferencer->deref(new Pointer(
            ZendCompilerGlobals::class,
            $cg_address,
            $zend_type_reader->sizeOf('zend_compiler_globals'),
        ));
        if ($cg->arena !== null) {
            $arena = $dereferencer->deref($cg->arena);
            foreach ($arena->iterateChain($dereferencer) as $a) {
                $addr = $a->getPointer()->address;
                $size = $a->end - $addr;
                if ($size > 0) {
                    $intervals[] = [
                        'address' => $addr,
                        'size' => $size,
                    ];
                }
            }
        }
        if ($cg->ast_arena !== null) {
            $arena = $dereferencer->deref($cg->ast_arena);
            foreach ($arena->iterateChain($dereferencer) as $a) {
                $addr = $a->getPointer()->address;
                $size = $a->end - $addr;
                if ($size > 0) {
                    $intervals[] = [
                        'address' => $addr,
                        'size' => $size,
                    ];
                }
            }
        }

        // CG(map_ptr_base) table: when opcache is ON, class_entry
        // static_members_table and other pointers use indirect
        // resolution via map_ptr. The table itself lives in [heap]
        // (allocated by opcache). Without it, resolveMapPtr() fails
        // and entire EmitClassTableJob/EmitFunctionTableJob skip.
        if ($cg->map_ptr_base !== 0) {
            try {
                [$mpl_off, $mpl_sz] = $zend_type_reader->getOffsetAndSizeOfMember(
                    'zend_compiler_globals',
                    'map_ptr_last',
                );
                $mpl_raw = \FFI::string(
                    $this->memory_reader->read(
                        $pid,
                        $cg_address + $mpl_off,
                        $mpl_sz,
                    ),
                    $mpl_sz,
                );
                /** @var array{1: int} */
                $mpl_u = unpack('P', $mpl_raw);
                $map_ptr_last = $mpl_u[1];
                if ($map_ptr_last > 0 && $map_ptr_last < 1000000) {
                    $table_size = $map_ptr_last * 8;
                    // PHP 8.0+ biased base: real_base = map_ptr_base + 1
                    $real_base = $cg->map_ptr_base + 1;
                    $intervals[] = [
                        'address' => $real_base,
                        'size' => $table_size,
                    ];
                }
            } catch (\Throwable) {
            }
        }

        // Optionally include read-only binary segments (self-contained
        // dumps). These rarely overlap with RW intervals because a
        // single VMA is either read-only or writable; merge handles
        // any accidental overlap anyway.
        if ($include_binary) {
            $php_ro_areas = $memory_map->findByNameRegex(
                $target_php_settings->php_regex,
            );
            foreach ($php_ro_areas as $area) {
                if (
                    !$area->attribute->write
                    && $area->attribute->read
                    && $area->name !== ''
                ) {
                    $addr = (int)hexdec($area->begin);
                    $size = (int)hexdec($area->end) - $addr;
                    if ($size > 0) {
                        $intervals[] = [
                            'address' => $addr,
                            'size' => $size,
                        ];
                    }
                }
            }
        }

        // Phase 2: sort by address and merge strictly overlapping
        // intervals. After this step the interval list is disjoint, so
        // each byte in the target is enumerated at most once and the
        // duplication that previously let the pagemap filter be
        // bypassed is eliminated.
        /** @var list<array{address: int, size: int}> $merged */
        $merged = self::mergeIntervals($intervals);

        // Phase 3: uniform pagemap residency filter applied once per
        // merged interval. Every region source benefits from
        // non-resident-page skipping -- including ZendMM chunks, huge
        // allocations, VM stacks and arenas -- without being bypassed
        // by duplicate unfiltered entries from another source.
        /** @var list<array{address: int, size: int}> $final */
        $final = [];
        foreach ($merged as $m) {
            $resident_runs = $this->findResidentRuns(
                $pid,
                $m['address'],
                $m['size'],
            );
            if ($resident_runs === null) {
                // pagemap unavailable: fall back to the full range.
                $final[] = $m;
                continue;
            }
            foreach ($resident_runs as $run) {
                $final[] = $run;
            }
        }

        // Phase 4 (minimum mode only): run the metadata peek walker to
        // cover the ~750 KiB of MINIT-time internal metadata that
        // lives in [heap] but is not captured by the bulk scan. The
        // walker uses the live Dereferencer so it reads from the
        // stopped target process, not from the dump file.
        if (!$include_heap) {
            $walker = new \Reli\Lib\PhpProcessReader\PhpMemoryReader\MetadataPeekWalker();
            $peeks = $walker->walk(
                $dereferencer,
                $zend_type_reader,
                $eg_address,
                $cg_address,
                $final,
            );

            // Opcache SHM interned string buffer: when opcache is ON,
            // interned strings are stored in a packed buffer in SHM.
            // The zend_accel_shared_globals struct is at SHM+0x88
            // (after the shared segment metadata). Walk the buffer
            // from start to top, peeking each zend_string that is
            // outside the covered intervals.
            $shm_areas = $memory_map->findByNameRegex('/dev/zero');
            foreach ($shm_areas as $shm_area) {
                if (!$shm_area->attribute->read) {
                    continue;
                }
                try {
                    $shm_base = (int)hexdec($shm_area->begin);
                    // zend_accel_shared_globals at SHM+0x88
                    $asg_addr = $shm_base + 0x88;
                    $is_offset = 160; // interned_strings field offset
                    $is_raw = \FFI::string(
                        $this->memory_reader->read($pid, $asg_addr + $is_offset, 40),
                        40,
                    );
                    /** @var array{1: int} */
                    $is_start_u = unpack('P', $is_raw, 8);
                    $is_start = $is_start_u[1];
                    /** @var array{1: int} */
                    $is_top_u = unpack('P', $is_raw, 16);
                    $is_top = $is_top_u[1];
                    $shm_end = (int)hexdec($shm_area->end);
                    if (
                        $is_start >= $shm_base && $is_start < $shm_end
                        && $is_top > $is_start && $is_top <= $shm_end
                    ) {
                        $buf_size = $is_top - $is_start;
                        $buf = \FFI::string(
                            $this->memory_reader->read($pid, $is_start, $buf_size),
                            $buf_size,
                        );
                        $str_hdr = $zend_type_reader->sizeOf('zend_string');
                        $off = 0;
                        $skip_count = 0;
                        while ($off + $str_hdr <= $buf_size) {
                            // Validate: interned strings have gc.refcount=2
                            /** @var array{1: int} */
                            $rc_u = unpack('V', $buf, $off);
                            $refcount = $rc_u[1];
                            if ($refcount !== 2) {
                                $off += 8;
                                $skip_count++;
                                if ($skip_count > 100) {
                                    break;
                                }
                                continue;
                            }
                            $skip_count = 0;
                            /** @var array{1: int} */
                            $len_u = unpack('P', $buf, $off + 16);
                            $len = $len_u[1];
                            if ($len > $buf_size) {
                                break;
                            }
                            $full_size = 24 + $len + 1;
                            $str_addr = $is_start + $off;
                            if (!self::isInIntervals($str_addr, $full_size, $final)) {
                                $peeks[] = ['address' => $str_addr, 'size' => $full_size];
                            }
                            $aligned = ($full_size + 7) & ~7;
                            $off += $aligned;
                        }
                    }
                } catch (\Throwable) {
                }
                break; // only process the first /dev/zero VMA
            }

            // Global interned-string pointer arrays (zend_one_char_string,
            // zend_known_strings, zend_empty_string). These are in BSS
            // and their string bodies are pemalloc'd in [heap]. Not in
            // CG(interned_strings) when opcache is OFF.
            $str_hdr_size = $zend_type_reader->sizeOf('zend_string');
            foreach ($interned_string_arrays as $arr) {
                try {
                    $arr_addr = $arr['address'];
                    $count = $arr['count'];

                    if ($count === -1) {
                        // Indirect pointer (zend_string **): deref once
                        // to get the heap-allocated array base, then
                        // scan entries until a null pointer.
                        $ptr_raw_single = \FFI::string(
                            $this->memory_reader->read($pid, $arr_addr, 8),
                            8,
                        );
                        /** @var array{val: int} $deref_u */
                        $deref_u = unpack('Pval', $ptr_raw_single);
                        $arr_addr = $deref_u['val'];
                        if ($arr_addr === 0) {
                            continue;
                        }
                        $count = 1024; // safe upper bound
                    }

                    $byte_len = $count * 8;
                    $ptr_data = $this->memory_reader->read(
                        $pid,
                        $arr_addr,
                        $byte_len,
                    );
                    $ptr_raw = \FFI::string($ptr_data, $byte_len);
                    $is_indirect = $arr['count'] === -1;
                    for ($ci = 0; $ci < $count; $ci++) {
                        /** @var array{val: int} $unpacked */
                        $unpacked = unpack('Pval', $ptr_raw, $ci * 8);
                        $str_addr = $unpacked['val'];
                        if ($str_addr === 0) {
                            if ($is_indirect) {
                                break; // null-terminated array end
                            }
                            continue;
                        }
                        if (self::isInIntervals($str_addr, $str_hdr_size, $final)) {
                            continue;
                        }
                        try {
                            $hdr = \FFI::string(
                                $this->memory_reader->read($pid, $str_addr, $str_hdr_size),
                                $str_hdr_size,
                            );
                            /** @var array{val: int} $len_u */
                            $len_u = unpack('Pval', $hdr, 16);
                            $str_len = $len_u['val'];
                            // Sanity: interned strings are short names, not multi-MB blobs.
                            if ($str_len > 0 && $str_len < 4096) {
                                $peeks[] = ['address' => $str_addr, 'size' => $str_hdr_size + $str_len];
                            } else {
                                $peeks[] = ['address' => $str_addr, 'size' => $str_hdr_size];
                            }
                        } catch (\Throwable) {
                            $peeks[] = ['address' => $str_addr, 'size' => $str_hdr_size];
                        }
                    }
                } catch (\Throwable) {
                }
            }
            foreach ($peeks as $peek) {
                $final[] = $peek;
            }
            // Page-align peek intervals, then re-merge with the
            // existing strict-overlap merge. Peeks on the same 4 KiB
            // page collapse into one region. This turns thousands of
            // tiny peek regions into ~20 contiguous page runs with
            // zero additional read cost (same pages are accessed
            // regardless of alignment padding).
            /** @var list<array{address: int, size: int}> $page_aligned */
            $page_aligned = [];
            foreach ($final as $iv) {
                $end = $iv['address'] + $iv['size'];
                $base = $iv['address'] & ~0xFFF;
                $page_aligned[] = [
                    'address' => $base,
                    'size' => (($end + 0xFFF) & ~0xFFF) - $base,
                ];
            }
            /** @var list<array{address: int, size: int}> $final */
            $final = self::mergeIntervals($page_aligned);
        }

        // Phase 5: read all regions and write the dump file.
        // Uses the existing MemoryReader (which handles process_vm_readv
        // via its own FFI instance) to avoid a second FFI cdef that
        // caused heap corruption on PHP 8.4 ZTS ARM.
        /** @var array<array{address: int, size: int, data: string}> $regions_data */
        $regions_data = [];
        foreach ($final as $entry) {
            try {
                $data = $this->memory_reader->read(
                    $pid,
                    $entry['address'],
                    $entry['size'],
                );
                $regions_data[] = [
                    'address' => $entry['address'],
                    'size' => $entry['size'],
                    'data' => \FFI::string($data, $entry['size']),
                ];
            } catch (\Throwable $e) {
                Log::info(
                    'skipping region at 0x' . dechex($entry['address'])
                    . ': ' . $e->getMessage(),
                );
            }
        }

        // All remote reads are now buffered locally as PHP strings, so the
        // target no longer has to stay stopped while we open the dump file
        // and stream the regions out to disk. Callers that pass a
        // `$on_read_complete` hook (currently the sidecar) take advantage of
        // this to release the ptrace stop early; the rest still pay the
        // combined read+write stop window.
        if ($on_read_complete !== null) {
            $on_read_complete();
        }

        $all_areas = $memory_map->findByNameRegex('.*');
        $writer = new MemoryDumpWriter();
        $writer->write(
            $output_path,
            $pid,
            $php_version,
            $eg_address,
            $cg_address,
            $all_areas,
            $regions_data,
            $rss_bytes,
        );

        $total_size = 0;
        foreach ($regions_data as $region) {
            $total_size += $region['size'];
        }

        return new MemoryDumpResult(
            output_path: $output_path,
            region_count: count($regions_data),
            total_bytes: $total_size,
        );
    }

    /**
     * Sort intervals by start address and merge strictly overlapping
     * entries. Adjacent-but-not-overlapping intervals are kept
     * separate so the pagemap residency pass is not forced to span a
     * VMA gap.
     *
     * @param list<array{address: int, size: int}> $intervals
     * @return list<array{address: int, size: int}>
     */
    /**
     * @param list<array{address: int, size: int}> $intervals sorted disjoint
     */
    private static function isInIntervals(int $addr, int $size, array $intervals): bool
    {
        $lo = 0;
        $hi = count($intervals) - 1;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            $iv = $intervals[$mid];
            $iv_end = $iv['address'] + $iv['size'];
            if ($addr >= $iv['address'] && ($addr + $size) <= $iv_end) {
                return true;
            }
            if ($addr < $iv['address']) {
                $hi = $mid - 1;
            } else {
                $lo = $mid + 1;
            }
        }
        return false;
    }

    /**
     * @param list<array{address: int, size: int}> $intervals
     * @return list<array{address: int, size: int}>
     */
    private static function mergeIntervals(array $intervals): array
    {
        if ($intervals === []) {
            return [];
        }
        // Drop zero-size entries so the merge loop below stays simple.
        /** @var list<array{address: int, size: int}> $intervals */
        $intervals = array_values(array_filter(
            $intervals,
            static fn (array $i): bool => $i['size'] > 0,
        ));
        if ($intervals === []) {
            return [];
        }
        usort(
            $intervals,
            /** @param array{address: int, size: int} $a @param array{address: int, size: int} $b */
            static fn (array $a, array $b): int
                => $a['address'] <=> $b['address'],
        );

        /** @var list<array{address: int, size: int}> $merged */
        $merged = [];
        $current = $intervals[0];
        $count = count($intervals);
        for ($i = 1; $i < $count; $i++) {
            $next = $intervals[$i];
            $cur_end = $current['address'] + $current['size'];
            if ($next['address'] < $cur_end) {
                // Overlap: extend the current interval if necessary.
                $next_end = $next['address'] + $next['size'];
                if ($next_end > $cur_end) {
                    $current['size'] = $next_end - $current['address'];
                }
            } else {
                $merged[] = $current;
                $current = $next;
            }
        }
        $merged[] = $current;
        return $merged;
    }

    private static ?\FFI $libc = null;

    private static function libc(): \FFI
    {
        if (self::$libc === null) {
            self::$libc = \FFI::cdef(
                'int open(const char *pathname, int flags);'
                . 'int close(int fd);'
                . 'long pread(int fd, void *buf, long count,'
                . ' long offset);',
                'libc.so.6',
            );
        }
        return self::$libc;
    }

    /**
     * Find contiguous runs of resident pages in a memory region
     * using /proc/pid/pagemap via direct syscalls.
     * Returns null if pagemap is inaccessible.
     * @return list<array{address: int, size: int}>|null
     */
    private function findResidentRuns(
        int $pid,
        int $region_addr,
        int $region_size,
    ): ?array {
        $libc = self::libc();
        $page_size = 4096;
        $start_vpn = intdiv($region_addr, $page_size);
        $end_vpn = intdiv($region_addr + $region_size + $page_size - 1, $page_size);
        $num_pages = $end_vpn - $start_vpn;
        if ($num_pages === 0) {
            return [['address' => $region_addr, 'size' => $region_size]];
        }

        $pagemap_path = "/proc/{$pid}/pagemap";
        /** @var int $fd */
        $fd = $libc->open($pagemap_path, 0); // O_RDONLY = 0
        if ($fd < 0) {
            return null;
        }

        $bytes_needed = $num_pages * 8;
        $buf = FFIHelper::new("char[{$bytes_needed}]");

        $offset = $start_vpn * 8;
        /** @var int $read */
        $read = $libc->pread($fd, $buf, $bytes_needed, $offset);
        $libc->close($fd);

        if ($read < 8) {
            return null;
        }
        $pages_read = (int)($read / 8);
        $data = \FFI::string($buf, $pages_read * 8);

        /** @var list<array{address: int, size: int}> $runs */
        $runs = [];
        $run_start_page = -1;
        $run_count = 0;

        for ($i = 0; $i < $pages_read; $i++) {
            /** @var array{val: int} $entry */
            $entry = unpack('Pval', $data, $i * 8);
            $present = ($entry['val'] >> 63) & 1;

            if ($present) {
                if ($run_start_page < 0) {
                    $run_start_page = $i;
                    $run_count = 1;
                } else {
                    $run_count++;
                }
            } else {
                if ($run_start_page >= 0) {
                    $runs[] = [
                        'address' => $region_addr
                            + $run_start_page * $page_size,
                        'size' => $run_count * $page_size,
                    ];
                    $run_start_page = -1;
                }
            }
        }
        if ($run_start_page >= 0) {
            $runs[] = [
                'address' => $region_addr
                    + $run_start_page * $page_size,
                'size' => $run_count * $page_size,
            ];
        }

        return $runs;
    }
}
