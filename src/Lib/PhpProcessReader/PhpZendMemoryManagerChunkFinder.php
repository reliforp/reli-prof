<?php

namespace Reli\Lib\PhpProcessReader;

use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Lib\PhpInternals\Types\Zend\ZendExecutorGlobals;
use Reli\Lib\PhpInternals\Types\Zend\ZendMmChunk;
use Reli\Lib\PhpInternals\ZendTypeReaderCreator;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreator;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\Pointer;
use Reli\Lib\Process\ProcessSpecifier;

/** @psalm-import-type VersionDecided from TargetPhpSettings */
class PhpZendMemoryManagerChunkFinder
{
    public function __construct(
        private ProcessMemoryMapCreator $process_memory_map_creator,
        private ZendTypeReaderCreator $zend_type_reader_creator,
    ) {
    }

    /** @param TargetPhpSettings<VersionDecided> $target_php_settings */
    public function findAddress(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings,
        int $eg_address,
        Dereferencer $dereferencer,
    ): ?int {
        $zend_type_reader = $this->zend_type_reader_creator->create($target_php_settings->php_version);
        $memory_map = $this->process_memory_map_creator->getProcessMemoryMap($process_specifier->pid);
        $eg_pointer = new Pointer(
            ZendExecutorGlobals::class,
            $eg_address,
            $zend_type_reader->sizeOf('zend_executor_globals'),
        );
        $eg = $dereferencer->deref($eg_pointer);
        $execute_data_address = $eg->current_execute_data?->address ?? 0;
        $process_memory_area = $memory_map->findByAddress($execute_data_address);
        foreach ($process_memory_area as $area) {
            $begin = hexdec($area->begin);
            $end = hexdec($area->end);

            for ($p = $this->alignAddress($begin, 0x200000); $p < $end; $p += 0x200000) {
                $pointer = new Pointer(
                    ZendMmChunk::class,
                    $p,
                    $zend_type_reader->sizeOf('zend_mm_chunk'),
                );
                $zend_mm_chunk = $dereferencer->deref($pointer);
                if (
                    $zend_mm_chunk->isInRange($execute_data_address)
                    and !is_null($zend_mm_chunk->heap)
                ) {
                    $heap = $dereferencer->deref($zend_mm_chunk->heap);
                    return $heap->main_chunk?->address;
                }
            }
        }
        return null;
    }

    private function alignAddress(int $address, int $align): int
    {
        if ($address % $align === 0) {
            return $address;
        }
        return $address + ($align - ($address % $align));
    }
}
