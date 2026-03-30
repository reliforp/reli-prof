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
     */
    public function dump(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings,
        int $eg_address,
        int $cg_address,
        string $output_path,
        bool $include_binary = false,
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

        // Enumerate all regions
        /** @var list<array{address: int, size: int}> $read_list */
        $read_list = [];

        // EG + CG structs
        $read_list[] = [
            'address' => $eg_address,
            'size' => $zend_type_reader->sizeOf(
                'zend_executor_globals',
            ),
        ];
        $read_list[] = [
            'address' => $cg_address,
            'size' => $zend_type_reader->sizeOf(
                'zend_compiler_globals',
            ),
        ];

        // ZendMM chunks
        foreach ($main_chunk->iterateChunks($dereferencer) as $chunk) {
            $read_list[] = [
                'address' => $chunk->getPointer()->address,
                'size' => ZendMmChunk::SIZE,
            ];
        }

        // Huge allocations
        $huge_list = $main_chunk->heap_slot->iterateHugeList(
            $dereferencer,
        );
        foreach ($huge_list as $huge) {
            $read_list[] = [
                'address' => $huge->ptr,
                'size' => $huge->size,
            ];
        }

        // [heap] region (internal function/class definitions).
        // After heavy extension usage + free, glibc returns pages via
        // MADV_DONTNEED but brk doesn't shrink, leaving non-resident
        // gaps. Use pagemap to skip them when [heap] is large.
        $heap_areas = $memory_map->findByNameRegex('\\[heap\\]');
        foreach ($heap_areas as $area) {
            $addr = (int)hexdec($area->begin);
            $size = (int)hexdec($area->end) - $addr;
            if ($size <= 0) {
                continue;
            }
            $resident_runs = $this->findResidentRuns(
                $pid,
                $addr,
                $size,
            );
            if ($resident_runs !== null && $resident_runs !== []) {
                foreach ($resident_runs as $run) {
                    $read_list[] = $run;
                }
            } else {
                $read_list[] = ['address' => $addr, 'size' => $size];
            }
        }

        // Opcache SHM regions (e.g. /dev/zero mmap).
        // When opcache is enabled, interned strings and cached scripts
        // live in shared memory that can be very large (128-320MB+)
        // but mostly empty. Use pagemap to identify resident pages.
        $shm_areas = $memory_map->findByNameRegex('/dev/zero');
        foreach ($shm_areas as $area) {
            if (!$area->attribute->read) {
                continue;
            }
            $addr = (int)hexdec($area->begin);
            $size = (int)hexdec($area->end) - $addr;
            if ($size <= 0) {
                continue;
            }
            $resident_runs = $this->findResidentRuns(
                $pid,
                $addr,
                $size,
            );
            if ($resident_runs !== null) {
                foreach ($resident_runs as $run) {
                    $read_list[] = $run;
                }
            } else {
                $read_list[] = ['address' => $addr, 'size' => $size];
            }
        }

        // Anonymous writable mmap regions.
        // glibc's malloc uses mmap for large allocations
        // (> M_MMAP_THRESHOLD, typically 128KB). Persistent PHP
        // data such as EG(function_table)->arData and interned
        // strings can end up here. Use pagemap to skip
        // non-resident pages.
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
                if ($size <= 0) {
                    continue;
                }
                $resident_runs = $this->findResidentRuns(
                    $pid,
                    $addr,
                    $size,
                );
                if ($resident_runs !== null && $resident_runs !== []) {
                    foreach ($resident_runs as $run) {
                        $read_list[] = $run;
                    }
                } else {
                    $read_list[] = [
                        'address' => $addr,
                        'size' => $size,
                    ];
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
                    $read_list[] = [
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
                        $read_list[] = [
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
                    $read_list[] = [
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
                    $read_list[] = [
                        'address' => $addr,
                        'size' => $size,
                    ];
                }
            }
        }

        // Bulk read all regions
        /** @var array<array{address: int, size: int, data: string}> $regions */
        $regions = [];
        foreach ($read_list as $entry) {
            try {
                $data = $this->memory_reader->read(
                    $pid,
                    $entry['address'],
                    $entry['size'],
                );
                $regions[] = [
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

        // Optionally include read-only binary segments
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
                        try {
                            $data = $this->memory_reader->read(
                                $pid,
                                $addr,
                                $size,
                            );
                            $regions[] = [
                                'address' => $addr,
                                'size' => $size,
                                'data' => \FFI::string($data, $size),
                            ];
                        } catch (\Throwable) {
                            // skip
                        }
                    }
                }
            }
        }

        // Write dump file
        $all_areas = $memory_map->findByNameRegex('.*');
        $writer = new MemoryDumpWriter();
        $writer->write(
            $output_path,
            $pid,
            $php_version,
            $eg_address,
            $cg_address,
            $all_areas,
            $regions,
        );

        $total_size = 0;
        foreach ($regions as $region) {
            $total_size += $region['size'];
        }

        return new MemoryDumpResult(
            output_path: $output_path,
            region_count: count($regions),
            total_bytes: $total_size,
        );
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
            return [];
        }
        $start_vpn = (int)($region_addr / $page_size);

        $pagemap_path = "/proc/{$pid}/pagemap";
        /** @var int $fd */
        $fd = $libc->open($pagemap_path, 0); // O_RDONLY = 0
        if ($fd < 0) {
            return null;
        }

        $bytes_needed = $num_pages * 8;
        $buf = \FFI::new("char[{$bytes_needed}]");
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
