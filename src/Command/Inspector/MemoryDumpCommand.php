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

namespace Reli\Command\Inspector;

use Reli\Inspector\MemoryDump\MemoryDumpWriter;
use Reli\Inspector\Settings\MemoryDumpSettings\MemoryDumpSettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetProcessSettings\TargetProcessSettingsFromConsoleInput;
use Reli\Inspector\TargetProcess\TargetProcessResolver;
use Reli\Lib\Elf\Process\BinaryAnalysisCache;
use Reli\Lib\Log\Log;
use Reli\Lib\PhpInternals\Types\Zend\ZendCastedTypeProvider;
use Reli\Lib\PhpInternals\Types\Zend\ZendCompilerGlobals;
use Reli\Lib\PhpInternals\Types\Zend\ZendExecutorGlobals;
use Reli\Lib\PhpInternals\Types\Zend\ZendMmChunk;
use Reli\Lib\PhpInternals\VersionedPointedTypeResolver;
use Reli\Lib\PhpInternals\ZendTypeReaderCreator;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpVersionDetector;
use Reli\Lib\PhpProcessReader\PhpZendMemoryManagerChunkFinder;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreatorInterface;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;
use Reli\Lib\Process\Pointer\Pointer;
use Reli\Lib\Process\Pointer\RemoteProcessDereferencer;
use Reli\Lib\Process\ProcessStopper\ProcessStopper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Reli\Lib\Defer\defer;

final class MemoryDumpCommand extends Command
{
    public function __construct(
        private PhpGlobalsFinder $php_globals_finder,
        private MemoryDumpSettingsFromConsoleInput $memory_dump_settings_from_console_input,
        private TargetPhpSettingsFromConsoleInput $target_php_settings_from_console_input,
        private TargetProcessSettingsFromConsoleInput $target_process_settings_from_console_input,
        private TargetProcessResolver $target_process_resolver,
        private PhpVersionDetector $php_version_detector,
        private MemoryReaderInterface $memory_reader,
        private ZendTypeReaderCreator $zend_type_reader_creator,
        private PhpZendMemoryManagerChunkFinder $chunk_finder,
        private ProcessStopper $process_stopper,
        private BinaryAnalysisCache $binary_analysis_cache,
        private ProcessMemoryMapCreatorInterface $process_memory_map_creator,
    ) {
        parent::__construct();
    }

    #[\Override]
    public function configure(): void
    {
        $this->setName('inspector:memory:dump')
            ->setDescription('[experimental] dump memory pool from a PHP process for offline analysis')
        ;
        $this->memory_dump_settings_from_console_input->setOptions($this);
        $this->target_process_settings_from_console_input->setOptions($this);
        $this->target_php_settings_from_console_input->setOptions($this);
        $this->addOption('no-cache', null, InputOption::VALUE_NONE, 'disable the binary analysis cache');
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('no-cache')) {
            $this->binary_analysis_cache->disable();
        }
        Log::info('start memory:dump command');

        $dump_settings = $this->memory_dump_settings_from_console_input->createSettings($input);
        $target_php_settings = $this->target_php_settings_from_console_input->createSettings($input);
        $target_process_settings = $this->target_process_settings_from_console_input->createSettings($input);

        $process_specifier = $this->target_process_resolver->resolve($target_process_settings);

        $target_php_settings_version_decided = $this->php_version_detector->decidePhpVersion(
            $process_specifier,
            $target_php_settings
        );

        $eg_address = $this->php_globals_finder->findExecutorGlobals(
            $process_specifier,
            $target_php_settings_version_decided
        );
        $cg_address = $this->php_globals_finder->findCompilerGlobals(
            $process_specifier,
            $target_php_settings_version_decided
        );

        $php_version = $target_php_settings_version_decided->php_version;
        $zend_type_reader = $this->zend_type_reader_creator->create($php_version);
        $dereferencer = new RemoteProcessDereferencer(
            $this->memory_reader,
            $process_specifier,
            new ZendCastedTypeProvider($zend_type_reader),
            new VersionedPointedTypeResolver($php_version),
        );

        $pid = $process_specifier->pid;
        $memory_map = $this->process_memory_map_creator->getProcessMemoryMap($pid);

        // Locate ZendMM chunks (needs dereferencer, done before stopping)
        $chunk_address = $this->chunk_finder->findAddress(
            $process_specifier,
            $target_php_settings_version_decided,
            $eg_address,
            $dereferencer,
        );
        if ($chunk_address === null) {
            throw new \RuntimeException('failed to find ZendMM main chunk');
        }
        $main_chunk_pointer = new Pointer(
            ZendMmChunk::class,
            $chunk_address,
            $zend_type_reader->sizeOf('zend_mm_chunk'),
        );
        $main_chunk = $dereferencer->deref($main_chunk_pointer);

        // Enumerate all regions to read (addresses + sizes)
        /** @var list<array{address: int, size: int}> $read_list */
        $read_list = [];

        // EG struct
        $eg_size = $zend_type_reader->sizeOf('zend_executor_globals');
        $read_list[] = ['address' => $eg_address, 'size' => $eg_size];

        // CG struct
        $cg_size = $zend_type_reader->sizeOf('zend_compiler_globals');
        $read_list[] = ['address' => $cg_address, 'size' => $cg_size];

        // ZendMM chunks
        foreach ($main_chunk->iterateChunks($dereferencer) as $chunk) {
            $read_list[] = [
                'address' => $chunk->getPointer()->address,
                'size' => ZendMmChunk::SIZE,
            ];
        }

        // Huge allocations
        foreach ($main_chunk->heap_slot->iterateHugeList($dereferencer) as $huge) {
            $read_list[] = ['address' => $huge->ptr, 'size' => $huge->size];
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
            $resident_runs = $this->findResidentRuns($pid, $addr, $size);
            if ($resident_runs !== null && $resident_runs !== []) {
                foreach ($resident_runs as $run) {
                    $read_list[] = $run;
                }
            } else {
                $read_list[] = ['address' => $addr, 'size' => $size];
            }
        }

        // Opcache SHM regions (e.g. /dev/zero mmap).
        // When opcache is enabled, interned strings and cached scripts live in
        // shared memory that can be very large (128-320MB+) but mostly empty.
        // We use /proc/pid/pagemap to identify resident pages and copy only those.
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
            $resident_runs = $this->findResidentRuns($pid, $addr, $size);
            if ($resident_runs !== null) {
                foreach ($resident_runs as $run) {
                    $read_list[] = $run;
                }
            } else {
                $read_list[] = ['address' => $addr, 'size' => $size];
            }
        }

        // PHP binary's writable data/BSS segments
        $php_rw_areas = $memory_map->findByNameRegex(
            $target_php_settings_version_decided->php_regex,
        );
        foreach ($php_rw_areas as $area) {
            if ($area->attribute->write && $area->attribute->read) {
                $addr = (int)hexdec($area->begin);
                $size = (int)hexdec($area->end) - $addr;
                if ($size > 0) {
                    $read_list[] = ['address' => $addr, 'size' => $size];
                }
            }
        }

        // VM stacks
        $eg = $dereferencer->deref(new Pointer(
            ZendExecutorGlobals::class,
            $eg_address,
            $eg_size,
        ));
        if ($eg->vm_stack !== null) {
            $vm_stack = $dereferencer->deref($eg->vm_stack);
            foreach ($vm_stack->iterateStackChain($dereferencer) as $stack) {
                if ($stack->end !== null) {
                    $addr = $stack->getPointer()->address;
                    $size = $stack->end->address - $addr;
                    if ($size > 0) {
                        $read_list[] = ['address' => $addr, 'size' => $size];
                    }
                }
            }
        }

        // Compiler arenas
        $cg = $dereferencer->deref(new Pointer(
            ZendCompilerGlobals::class,
            $cg_address,
            $cg_size,
        ));
        if ($cg->arena !== null) {
            $arena_root = $dereferencer->deref($cg->arena);
            foreach ($arena_root->iterateChain($dereferencer) as $arena) {
                $addr = $arena->getPointer()->address;
                $size = $arena->end - $addr;
                if ($size > 0) {
                    $read_list[] = ['address' => $addr, 'size' => $size];
                }
            }
        }
        if ($cg->ast_arena !== null) {
            $arena_root = $dereferencer->deref($cg->ast_arena);
            foreach ($arena_root->iterateChain($dereferencer) as $arena) {
                $addr = $arena->getPointer()->address;
                $size = $arena->end - $addr;
                if ($size > 0) {
                    $read_list[] = ['address' => $addr, 'size' => $size];
                }
            }
        }

        // ---- Stop the process and bulk-read all regions ----
        if ($dump_settings->stop_process) {
            $this->process_stopper->stop($pid);
            defer($scope_guard, fn () => $this->process_stopper->resume($pid));
        }

        /** @var array<array{address: int, size: int, data: string}> $regions */
        $regions = [];
        foreach ($read_list as $entry) {
            try {
                $data = $this->memory_reader->read($pid, $entry['address'], $entry['size']);
                $regions[] = [
                    'address' => $entry['address'],
                    'size' => $entry['size'],
                    'data' => \FFI::string($data, $entry['size']),
                ];
            } catch (\Throwable $e) {
                Log::info(
                    "skipping region at 0x" . dechex($entry['address'])
                    . ": " . $e->getMessage()
                );
            }
        }

        // Process is resumed here (defer scope guard)
        // ---- File writing happens after resume ----

        // Optionally include read-only binary segments
        if ($dump_settings->include_binary) {
            $php_ro_areas = $memory_map->findByNameRegex(
                $target_php_settings_version_decided->php_regex,
            );
            foreach ($php_ro_areas as $area) {
                if (!$area->attribute->write && $area->attribute->read && $area->name !== '') {
                    $addr = (int)hexdec($area->begin);
                    $size = (int)hexdec($area->end) - $addr;
                    if ($size > 0) {
                        try {
                            $data = $this->memory_reader->read($pid, $addr, $size);
                            $regions[] = [
                                'address' => $addr,
                                'size' => $size,
                                'data' => \FFI::string($data, $size),
                            ];
                        } catch (\Throwable $e) {
                            Log::info(
                                "skipping binary region at 0x" . dechex($addr)
                                . ": " . $e->getMessage()
                            );
                        }
                    }
                }
            }
        }

        $all_areas = $memory_map->findByNameRegex('.*');
        $writer = new MemoryDumpWriter();
        $writer->write(
            $dump_settings->output_path,
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

        $output->writeln(sprintf(
            'Memory dump written to %s (%d regions, %.1f MB)',
            $dump_settings->output_path,
            count($regions),
            (float)$total_size / 1024.0 / 1024.0,
        ));

        Log::info('end memory:dump command');
        return 0;
    }

    private static ?\FFI $libc = null;

    private static function libc(): \FFI
    {
        if (self::$libc === null) {
            self::$libc = \FFI::cdef(
                'int open(const char *pathname, int flags);'
                . 'int close(int fd);'
                . 'long pread(int fd, void *buf, long count, long offset);',
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
                        'address' => $region_addr + $run_start_page * $page_size,
                        'size' => $run_count * $page_size,
                    ];
                    $run_start_page = -1;
                }
            }
        }
        if ($run_start_page >= 0) {
            $runs[] = [
                'address' => $region_addr + $run_start_page * $page_size,
                'size' => $run_count * $page_size,
            ];
        }

        return $runs;
    }
}
