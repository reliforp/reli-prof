<?php

namespace Reli\Lib\PhpProcessReader;

use Reli\Lib\PhpInternals\ZendTypeReaderCreator;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreator;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;

class PhpZendMemoryManagerChunkFinder
{
    public function __construct(
        private ProcessMemoryMapCreator $process_memory_map_creator,
        private ZendTypeReaderCreator $zend_type_reader_creator,
    ) {
    }
    public function findAddress(
        int $pid,
        string $php_version,
        MemoryReaderInterface $memory_reader,
    ): int {
        $zend_type_reader = $this->zend_type_reader_creator->create($php_version);
        $memory_map = $this->process_memory_map_creator->getProcessMemoryMap($pid);
        $process_memory_area = $memory_map->findByNameRegex('\[anon:zend_alloc\]');
        foreach ($process_memory_area as $area) {
            $begin = hexdec($area->begin);
            $end = hexdec($area->end);
            for ($p = $begin; $p < $end; $p += 0x200000) {
                $zend_mm_chunk_buffer = $memory_reader->read(
                    $pid,
                    $p,
                    $zend_type_reader->sizeOf('zend_mm_chunk'),
                );
                $zend_mm_chunk = $zend_type_reader->readAs('zend_mm_chunk', $zend_mm_chunk_buffer);
                $heap_address = \FFI::cast('long', $zend_mm_chunk->casted->heap)->cdata;
                [$offset,] = $zend_type_reader->getOffsetAndSizeOfMember('zend_mm_chunk', 'heap_slot');
                if (
                    $heap_address === $p + $offset
                    and $zend_mm_chunk->casted->num === 0
                    and $zend_mm_chunk->casted->heap_slot->size > 0
                ) {
                    return $p;
                }
            }
        }
        return hexdec($process_memory_area[0]->begin);
    }
}