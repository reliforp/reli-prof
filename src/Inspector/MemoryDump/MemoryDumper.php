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

        // Anonymous writable mmap regions. In include_heap mode, these
        // are captured for full coverage; in minimum mode they are
        // skipped because the ZendMM chunk / huge entries above
        // already cover the PHP-owned portion, and the remainder is
        // glibc malloc arenas and extension-private state that the
        // analyser does not walk.
        if ($include_heap) {
            $anon_areas = $memory_map->findByNameRegex('^$');
            foreach ($anon_areas as $area) {
                if (
                    $area->inode_num === 0
                    && $area->attribute->read
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

            // Global interned-string pointer arrays (zend_one_char_string,
            // zend_known_strings, zend_empty_string). These are in BSS
            // and their string bodies are pemalloc'd in [heap]. Not in
            // CG(interned_strings) when opcache is OFF.
            $str_hdr_size = $zend_type_reader->sizeOf('zend_string');
            foreach ($interned_string_arrays as $arr) {
                try {
                    $arr_addr = $arr['address'];
                    // count=0 means "read as many pointers as the BSS
                    // region allows" — determine from the array's known
                    // ELF symbol size or cap at a safe maximum.
                    $count = $arr['count'] > 0 ? $arr['count'] : 256;
                    $byte_len = $count * 8;
                    $ptr_data = $this->memory_reader->read(
                        $pid,
                        $arr_addr,
                        $byte_len,
                    );
                    $ptr_raw = \FFI::string($ptr_data, $byte_len);
                    for ($ci = 0; $ci < $count; $ci++) {
                        /** @var array{val: int} $unpacked */
                        $unpacked = unpack('Pval', $ptr_raw, $ci * 8);
                        $str_addr = $unpacked['val'];
                        if (
                            $str_addr === 0
                            || self::isInIntervals($str_addr, $str_hdr_size, $final)
                        ) {
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

        // Phase 5: stream-write. Read each region and flush it to disk
        // one at a time. Peak profiler memory drops from
        // `O(dump size)` to `O(max single region size)` because FFI
        // buffers and the PHP string copies are released as soon as
        // the writer advances to the next iteration.
        $all_areas = $memory_map->findByNameRegex('.*');
        $writer = new MemoryDumpWriter();
        $result = $writer->writeStreaming(
            $output_path,
            $pid,
            $php_version,
            $eg_address,
            $cg_address,
            $all_areas,
            count($final),
            $this->streamRegionReads($pid, $final),
        );

        return new MemoryDumpResult(
            output_path: $output_path,
            region_count: $result['region_count'],
            total_bytes: $result['total_bytes'],
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

    /**
     * Generator that reads each planned region from the target process
     * and yields it to the writer. Per-region FFI buffers and PHP
     * string copies are released as soon as the writer advances, so
     * peak memory is bounded by the largest single region rather than
     * the total dump size.
     *
     * @param list<array{address: int, size: int}> $regions
     * @return \Generator<int, array{address: int, size: int, data: string}>
     */
    private function streamRegionReads(int $pid, array $regions): \Generator
    {
        foreach ($regions as $entry) {
            try {
                $data = $this->memory_reader->read(
                    $pid,
                    $entry['address'],
                    $entry['size'],
                );
            } catch (\Throwable $e) {
                Log::info(
                    'skipping region at 0x' . dechex($entry['address'])
                    . ': ' . $e->getMessage(),
                );
                continue;
            }
            yield [
                'address' => $entry['address'],
                'size' => $entry['size'],
                'data' => \FFI::string($data, $entry['size']),
            ];
        }
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
        $num_pages = (int)($region_size / $page_size);
        if ($num_pages === 0) {
            // Sub-page region: too small to probe via pagemap (it
            // always fits inside a single page). Treat it as
            // resident — returning null would also work (it means
            // "pagemap unavailable, keep the whole range"), but
            // returning the region itself is more explicit.
            return [['address' => $region_addr, 'size' => $region_size]];
        }
        $start_vpn = (int)($region_addr / $page_size);

        $pagemap_path = "/proc/{$pid}/pagemap";
        /** @var int $fd */
        $fd = $libc->open($pagemap_path, 0); // O_RDONLY = 0
        if ($fd < 0) {
            return null;
        }

        $bytes_needed = $num_pages * 8;
        $buf = FFIHelper::new("char[{$bytes_needed}]");
        if ($buf === null) {
            $libc->close($fd);
            return null;
        }

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
