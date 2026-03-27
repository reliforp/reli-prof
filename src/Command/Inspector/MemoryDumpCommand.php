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
use Reli\Inspector\Settings\MemoryDumpSettings\MemoryDumpScope;
use Reli\Inspector\Settings\MemoryDumpSettings\MemoryDumpSettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetProcessSettings\TargetProcessSettingsFromConsoleInput;
use Reli\Inspector\TargetProcess\TargetProcessResolver;
use Reli\Lib\Elf\Process\BinaryAnalysisCache;
use Reli\Lib\Log\Log;
use Reli\Lib\PhpInternals\Types\Zend\ZendArena;
use Reli\Lib\PhpInternals\Types\Zend\ZendCastedTypeProvider;
use Reli\Lib\PhpInternals\Types\Zend\ZendCompilerGlobals;
use Reli\Lib\PhpInternals\Types\Zend\ZendExecutorGlobals;
use Reli\Lib\PhpInternals\Types\Zend\ZendMmChunk;
use Reli\Lib\PhpInternals\VersionedPointedTypeResolver;
use Reli\Lib\PhpInternals\ZendTypeReaderCreator;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpVersionDetector;
use Reli\Lib\PhpProcessReader\PhpZendMemoryManagerChunkFinder;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreator;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreatorInterface;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;
use Reli\Lib\Process\Pointer\Pointer;
use Reli\Lib\Process\Pointer\RemoteProcessDereferencer;
use Reli\Lib\Process\ProcessSpecifier;
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

        if ($dump_settings->stop_process) {
            $this->process_stopper->stop($process_specifier->pid);
            defer($scope_guard, fn () => $this->process_stopper->resume($process_specifier->pid));
        }

        $php_version = $target_php_settings_version_decided->php_version;
        $zend_type_reader = $this->zend_type_reader_creator->create($php_version);
        $dereferencer = new RemoteProcessDereferencer(
            $this->memory_reader,
            $process_specifier,
            new ZendCastedTypeProvider($zend_type_reader),
            new VersionedPointedTypeResolver($php_version),
        );

        $pid = $process_specifier->pid;

        /** @var array<array{address: int, size: int, data: string}> $regions */
        $regions = [];

        // 1. Read EG struct
        $eg_size = $zend_type_reader->sizeOf('zend_executor_globals');
        $eg_data = $this->memory_reader->read($pid, $eg_address, $eg_size);
        $regions[] = [
            'address' => $eg_address,
            'size' => $eg_size,
            'data' => \FFI::string($eg_data, $eg_size),
        ];

        // 2. Read CG struct
        $cg_size = $zend_type_reader->sizeOf('zend_compiler_globals');
        $cg_data = $this->memory_reader->read($pid, $cg_address, $cg_size);
        $regions[] = [
            'address' => $cg_address,
            'size' => $cg_size,
            'data' => \FFI::string($cg_data, $cg_size),
        ];

        // 3. Find and read ZendMM chunks
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

        $chunk_count = 0;
        foreach ($main_chunk->iterateChunks($dereferencer) as $chunk) {
            $addr = $chunk->getPointer()->address;
            $data = $this->memory_reader->read($pid, $addr, ZendMmChunk::SIZE);
            $regions[] = [
                'address' => $addr,
                'size' => ZendMmChunk::SIZE,
                'data' => \FFI::string($data, ZendMmChunk::SIZE),
            ];
            $chunk_count++;
        }

        // 4. Read huge allocations
        $huge_count = 0;
        foreach ($main_chunk->heap_slot->iterateHugeList($dereferencer) as $huge) {
            $addr = $huge->ptr;
            $size = $huge->size;
            $data = $this->memory_reader->read($pid, $addr, $size);
            $regions[] = [
                'address' => $addr,
                'size' => $size,
                'data' => \FFI::string($data, $size),
            ];
            $huge_count++;
        }

        // 5. Capture writable memory regions that EG/CG pointers reference
        //    (function_table, class_table, interned_strings, objects_store, etc.)
        //    Instead of dumping the entire [heap], we find which memory map regions
        //    contain addresses referenced by EG/CG pointer fields, and capture only those.
        $extra_count = 0;
        $memory_map = $this->process_memory_map_creator->getProcessMemoryMap($pid);
        $eg_pointer = new Pointer(
            ZendExecutorGlobals::class,
            $eg_address,
            $eg_size,
        );
        $eg = $dereferencer->deref($eg_pointer);
        $cg_pointer = new Pointer(
            ZendCompilerGlobals::class,
            $cg_address,
            $cg_size,
        );
        $cg = $dereferencer->deref($cg_pointer);

        // Collect addresses that EG/CG reference outside ZendMM chunks
        $referenced_addresses = [];
        $eg_cg_pointers = [
            $eg->function_table,
            $eg->class_table,
            $eg->zend_constants,
            $eg->vm_stack,
            $eg->vm_stack_top,
            $eg->current_execute_data,
            $cg->arena,
            $cg->ast_arena,
        ];
        foreach ($eg_cg_pointers as $ptr) {
            if ($ptr !== null) {
                $referenced_addresses[] = $ptr->address;
            }
        }
        // Also add embedded struct field addresses (symbol_table, included_files, objects_store)
        // These are inline in EG so their arData pointers may reference external memory
        $inline_arrays = [
            $eg->symbol_table,
            $eg->included_files,
        ];
        foreach ($inline_arrays as $arr) {
            if ($arr->arData !== null) {
                $referenced_addresses[] = $arr->arData->address;
            }
        }
        if ($eg->objects_store->object_buckets !== null) {
            $referenced_addresses[] = $eg->objects_store->object_buckets->address;
        }
        if ($cg->interned_strings->arData !== null) {
            $referenced_addresses[] = $cg->interned_strings->arData->address;
        }

        // Find memory map regions that contain referenced addresses
        // but are NOT already covered by ZendMM chunks
        /** @var array<string, true> $captured_regions */
        $captured_regions = [];
        foreach ($referenced_addresses as $ref_addr) {
            // Check if already in a ZendMM chunk
            $in_chunk = false;
            foreach ($regions as $region) {
                if (
                    $ref_addr >= $region['address']
                    && $ref_addr < $region['address'] + $region['size']
                ) {
                    $in_chunk = true;
                    break;
                }
            }
            if ($in_chunk) {
                continue;
            }

            $areas = $memory_map->findByAddress($ref_addr);
            foreach ($areas as $area) {
                if (!$area->attribute->write || !$area->attribute->read) {
                    continue;
                }
                $region_key = $area->begin . '-' . $area->end;
                if (isset($captured_regions[$region_key])) {
                    continue;
                }
                $captured_regions[$region_key] = true;
                $addr = (int)hexdec($area->begin);
                $size = (int)hexdec($area->end) - $addr;
                if ($size > 0) {
                    $size_mb = (float)$size / 1024.0 / 1024.0;
                    if ($size_mb > 50.0) {
                        $output->writeln(sprintf(
                            '<comment>Warning: capturing large region'
                            . ' %s (%.1f MB) referenced by EG/CG'
                            . ' pointer</comment>',
                            $area->name !== '' ? $area->name : 'anon',
                            $size_mb,
                        ));
                    }
                    try {
                        $data = $this->memory_reader->read($pid, $addr, $size);
                        $regions[] = [
                            'address' => $addr,
                            'size' => $size,
                            'data' => \FFI::string($data, $size),
                        ];
                        $extra_count++;
                    } catch (\Throwable $e) {
                        Log::info(
                            "skipping region at 0x" . dechex($addr)
                            . ": " . $e->getMessage()
                        );
                    }
                }
            }
        }

        // 6. Read VM stacks and compiler arenas (default scope only)
        $vm_stack_count = 0;
        $arena_count = 0;
        if ($dump_settings->scope === MemoryDumpScope::Default_) {
            // VM stacks (reuse $eg from above)
            if ($eg->vm_stack !== null) {
                $vm_stack = $dereferencer->deref($eg->vm_stack);
                foreach ($vm_stack->iterateStackChain($dereferencer) as $stack) {
                    if ($stack->end === null) {
                        continue;
                    }
                    $addr = $stack->getPointer()->address;
                    $size = $stack->end->address - $addr;
                    if ($size > 0) {
                        $data = $this->memory_reader->read($pid, $addr, $size);
                        $regions[] = [
                            'address' => $addr,
                            'size' => $size,
                            'data' => \FFI::string($data, $size),
                        ];
                        $vm_stack_count++;
                    }
                }
            }

            // Compiler arenas (reuse $cg from above)
            if ($cg->arena !== null) {
                $arena_root = $dereferencer->deref($cg->arena);
                foreach ($arena_root->iterateChain($dereferencer) as $arena) {
                    $addr = $arena->getPointer()->address;
                    $size = $arena->end - $addr;
                    if ($size > 0) {
                        $data = $this->memory_reader->read($pid, $addr, $size);
                        $regions[] = [
                            'address' => $addr,
                            'size' => $size,
                            'data' => \FFI::string($data, $size),
                        ];
                        $arena_count++;
                    }
                }
            }

            if ($cg->ast_arena !== null) {
                $ast_arena_root = $dereferencer->deref($cg->ast_arena);
                foreach ($ast_arena_root->iterateChain($dereferencer) as $ast_arena) {
                    $addr = $ast_arena->getPointer()->address;
                    $size = $ast_arena->end - $addr;
                    if ($size > 0) {
                        $data = $this->memory_reader->read($pid, $addr, $size);
                        $regions[] = [
                            'address' => $addr,
                            'size' => $size,
                            'data' => \FFI::string($data, $size),
                        ];
                        $arena_count++;
                    }
                }
            }
        }

        // 7. Include binary segments if requested
        if ($dump_settings->include_binary) {
            $php_ro_areas = $memory_map->findByNameRegex($php_regex);
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
                            Log::info("skipping binary region at 0x" . dechex($addr) . ": " . $e->getMessage());
                        }
                    }
                }
            }
        }

        // Extract all areas from ProcessMemoryMap for the dump file
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
            'Memory dump written to %s (%s, %d regions: %d chunks, %d huge,'
            . ' %d extra, %d vm_stacks, %d arenas, %.1f MB)',
            $dump_settings->output_path,
            $dump_settings->scope->value,
            count($regions),
            $chunk_count,
            $huge_count,
            $extra_count,
            $vm_stack_count,
            $arena_count,
            (float)$total_size / 1024.0 / 1024.0,
        ));

        Log::info('end memory:dump command');
        return 0;
    }
}
