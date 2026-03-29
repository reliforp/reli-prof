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

namespace Reli\Inspector\Watch\Action;

use Reli\Inspector\MemoryDump\MemoryDumpWriter;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Inspector\Watch\DiskUsageTracker;
use Reli\Inspector\Watch\TriggerEvent;
use Reli\Inspector\Watch\WatchContext;
use Reli\Lib\Log\Log;
use Reli\Lib\PhpInternals\Types\Zend\ZendCastedTypeProvider;
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
 * Fast binary memory dump action.
 *
 * Dumps ZendMM chunks + huge allocations as a binary file
 * (same format as inspector:memory:dump) for offline analysis.
 * Much faster than the full analysis path (inspector:memory).
 */
final class MemoryDumpAction implements ActionInterface
{
    public function __construct(
        private MemoryReaderInterface $memory_reader,
        private ZendTypeReaderCreator $zend_type_reader_creator,
        private PhpZendMemoryManagerChunkFinder $chunk_finder,
        private ProcessMemoryMapCreatorInterface $process_memory_map_creator,
        /** @var TargetPhpSettings<'v70'|'v71'|'v72'|'v73'|'v74'|'v80'|'v81'|'v82'|'v83'|'v84'|'v85'> */
        private TargetPhpSettings $target_php_settings,
        private int $eg_address,
        private int $cg_address,
        private string $output_dir,
        private DiskUsageTracker $disk_tracker,
    ) {
    }

    #[\Override]
    public function name(): string
    {
        return 'memory-dump';
    }

    #[\Override]
    public function execute(
        TriggerEvent $event,
        ProcessSpecifier $process,
        WatchContext $context,
    ): void {
        if (!$this->disk_tracker->canWrite()) {
            Log::debug('memory-dump skipped: disk limit reached');
            return;
        }

        $output_path = sprintf(
            '%s/watch-%d-%s.dump',
            rtrim($this->output_dir, '/'),
            $process->pid,
            date('Ymd-His', (int)$event->timestamp),
        );

        try {
            $php_version = $this->target_php_settings->php_version;
            $zend_type_reader = $this->zend_type_reader_creator
                ->create($php_version);
            $dereferencer = new RemoteProcessDereferencer(
                $this->memory_reader,
                $process,
                new ZendCastedTypeProvider($zend_type_reader),
                new VersionedPointedTypeResolver($php_version),
            );

            // Find main chunk
            $chunk_address = $this->chunk_finder->findAddress(
                $process,
                $this->target_php_settings,
                $this->eg_address,
                $dereferencer,
            );
            if ($chunk_address === null) {
                Log::debug('memory-dump: chunk not found');
                return;
            }

            $main_chunk = $dereferencer->deref(new Pointer(
                ZendMmChunk::class,
                $chunk_address,
                $zend_type_reader->sizeOf('zend_mm_chunk'),
            ));

            // Build read list: EG + CG + chunks + huge allocs
            /** @var list<array{address: int, size: int}> $read_list */
            $read_list = [];

            $read_list[] = [
                'address' => $this->eg_address,
                'size' => $zend_type_reader->sizeOf(
                    'zend_executor_globals',
                ),
            ];
            $read_list[] = [
                'address' => $this->cg_address,
                'size' => $zend_type_reader->sizeOf(
                    'zend_compiler_globals',
                ),
            ];

            foreach ($main_chunk->iterateChunks($dereferencer) as $chunk) {
                $read_list[] = [
                    'address' => $chunk->getPointer()->address,
                    'size' => ZendMmChunk::SIZE,
                ];
            }

            $huge_list = $main_chunk->heap_slot->iterateHugeList(
                $dereferencer,
            );
            foreach ($huge_list as $huge) {
                $read_list[] = [
                    'address' => $huge->ptr,
                    'size' => $huge->size,
                ];
            }

            // Bulk read
            /** @var array<array{address: int, size: int, data: string}> $regions */
            $regions = [];
            foreach ($read_list as $entry) {
                try {
                    $data = $this->memory_reader->read(
                        $process->pid,
                        $entry['address'],
                        $entry['size'],
                    );
                    $regions[] = [
                        'address' => $entry['address'],
                        'size' => $entry['size'],
                        'data' => \FFI::string($data, $entry['size']),
                    ];
                } catch (\Throwable) {
                    continue;
                }
            }

            // Write dump file
            $memory_map = $this->process_memory_map_creator
                ->getProcessMemoryMap($process->pid);
            $all_areas = $memory_map->findByNameRegex('.*');

            $writer = new MemoryDumpWriter();
            $writer->write(
                $output_path,
                $process->pid,
                $php_version,
                $this->eg_address,
                $this->cg_address,
                $all_areas,
                $regions,
            );

            $this->disk_tracker->recordFile($output_path);
            Log::info('memory-dump saved', ['path' => $output_path]);
        } catch (\Throwable $e) {
            Log::debug('memory-dump failed', [
                'pid' => $process->pid,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
