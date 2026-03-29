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

namespace Reli\Inspector\Watch;

use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Lib\PhpInternals\Types\Zend\ZendCastedTypeProvider;
use Reli\Lib\PhpInternals\Types\Zend\ZendMmChunk;
use Reli\Lib\PhpInternals\VersionedPointedTypeResolver;
use Reli\Lib\PhpInternals\ZendTypeReaderCreator;
use Reli\Lib\PhpProcessReader\PhpZendMemoryManagerChunkFinder;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\Pointer;
use Reli\Lib\Process\Pointer\RemoteProcessDereferencer;
use Reli\Lib\Process\ProcessSpecifier;

/**
 * Lightweight heap statistics reader.
 *
 * Unlike MemoryLocationsCollector::collectAll() which performs a full memory scan,
 * this reader only reads a few fields from ZendMmHeap via the main chunk,
 * completing in < 1ms with a single process_vm_readv call.
 */
final class HeapStatsReader
{
    public function __construct(
        private PhpZendMemoryManagerChunkFinder $chunk_finder,
        private MemoryReaderInterface $memory_reader,
        private ZendTypeReaderCreator $zend_type_reader_creator,
    ) {
    }

    /**
     * @param TargetPhpSettings<'v70'|'v71'|'v72'|'v73'|'v74'|'v80'|'v81'|'v82'|'v83'|'v84'|'v85'> $target_php_settings
     */
    public function read(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings,
        int $eg_address,
    ): HeapStats {
        $php_version = $target_php_settings->php_version;
        $dereferencer = $this->getDereferencer($process_specifier, $php_version);
        $zend_type_reader = $this->zend_type_reader_creator->create($php_version);

        $chunk_address = $this->chunk_finder->findAddress(
            $process_specifier,
            $target_php_settings,
            $eg_address,
            $dereferencer,
        );
        if (is_null($chunk_address)) {
            throw new \RuntimeException('chunk address not found');
        }

        $main_chunk_pointer = new Pointer(
            ZendMmChunk::class,
            $chunk_address,
            $zend_type_reader->sizeOf('zend_mm_chunk'),
        );

        $zend_mm_main_chunk = $dereferencer->deref($main_chunk_pointer);
        $heap = $zend_mm_main_chunk->heap_slot;

        return new HeapStats(
            size: $heap->size,
            real_size: $heap->real_size,
            peak: $heap->peak,
            limit: $heap->limit,
        );
    }

    /**
     * @param value-of<\Reli\Lib\PhpInternals\ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     */
    private function getDereferencer(ProcessSpecifier $process_specifier, string $php_version): Dereferencer
    {
        $zend_type_reader = $this->zend_type_reader_creator->create($php_version);
        return new RemoteProcessDereferencer(
            $this->memory_reader,
            $process_specifier,
            new ZendCastedTypeProvider($zend_type_reader),
            new VersionedPointedTypeResolver($php_version),
        );
    }
}
