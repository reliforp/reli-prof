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

namespace Reli\Inspector\CoreDumpReader;

use DI\Container;
use DI\ContainerBuilder;
use FFI\CData;
use Reli\Lib\ByteStream\ByteReaderInterface;
use Reli\Lib\ByteStream\StringByteReader;
use Reli\Lib\Elf\Parser\Elf64Parser;
use Reli\Lib\Elf\Structure\Elf64\Elf64Note;
use Reli\Lib\Elf\Structure\Elf64\NtFileEntry;
use Reli\Lib\File\PathResolver\MappedPathResolver;
use Reli\Lib\File\PathResolver\ProcessPathResolver;
use Reli\Lib\Integer\UInt64;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocationsCollector;
use Reli\Lib\PhpProcessReader\PhpVersionDetector;
use Reli\Lib\Process\MemoryMap\ProcessMemoryArea;
use Reli\Lib\Process\MemoryMap\ProcessMemoryAttribute;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMap;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreatorInterface;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;

use function dechex;
use function DI\autowire;
use function fclose;
use function fopen;
use function fread;
use function fseek;

class CoreDumpReaderFactory
{
    public function __construct(
        private ContainerBuilder $container_builder,
        private Elf64Parser $elf64_parser,
    ) {
    }

    /** @param array<string, string> $path_mapping */
    public function createFromPath(string $file_path, array $path_mapping): CoreDumpReader
    {
        $contents = file_get_contents($file_path);
        if ($contents === false) {
            throw new \RuntimeException("failed to read file: $file_path");
        }
        $binary = new StringByteReader($contents);
        $elf_header = $this->elf64_parser->parseElfHeader($binary);
        if (!$elf_header->isCore()) {
            throw new \RuntimeException("not a core dump file: $file_path");
        }
        $program_headers = $this->elf64_parser->parseProgramHeader($binary, $elf_header);
        $load_segments = $program_headers->findLoad();
        $notes = [];
        foreach ($program_headers->findNote() as $note_entry) {
            $notes = [
                ...$notes,
                ...$this->elf64_parser->parseNote(
                    $binary,
                    $note_entry
                )
            ];
        }
        $threads = [];
        $current_thread_info = [];
        $file_maps = [];
        /** @var Elf64Note $note */
        foreach ($notes as $note) {
            if ($note->isCore()) {
                if ($note->isPrStatus()) {
                    if ($current_thread_info !== []) {
                        $threads[] = $current_thread_info;
                    }
                    $current_thread_info = [
                        $this->elf64_parser->parsePrStatus($note),
                    ];
                }
            }
            if ($note->isFile()) {
                $file_maps = [
                    ...$file_maps,
                    ...$this->elf64_parser->parseNtFile($note)
                ];
            }
        }
        if ($current_thread_info !== []) {
            $threads[] = $current_thread_info;
        }
        $memory_areas = [];
        foreach ($load_segments as $load_segment) {
            $corresponding_file = null;
            /** @var NtFileEntry $file_map */
            foreach ($file_maps as $file_map) {
                if ($file_map->isInRange($load_segment->p_vaddr)) {
                    $corresponding_file = $file_map;
                    break;
                }
            }
            $file_offset = $load_segment->p_offset->toInt();
            if (!$load_segment->isWritable()) {
                if ($corresponding_file !== null) {
                    $file_offset = $corresponding_file->file_offset->toInt();
                }
            }
            $file_path = $corresponding_file?->name ?? '';
            $file_inode = $file_path === '' ? 0 : fileinode($file_path);

            $memory_areas[] = new ProcessMemoryArea(
                dechex($load_segment->p_vaddr->toInt()),
                dechex($load_segment->p_vaddr->toInt() + $load_segment->p_memsz->toInt()),
                dechex($file_offset),
                new ProcessMemoryAttribute(
                    $load_segment->isReadable(),
                    $load_segment->isWritable(),
                    $load_segment->isExecutable(),
                    false,
                ),
                '00:00', // dummy
                $file_inode,
                $file_path,
            );
        }
        $process_memory_map = new ProcessMemoryMap($memory_areas);
        $memory_reader = new class ($binary, $process_memory_map, $file_maps) implements MemoryReaderInterface {
            /** @param NtFileEntry[] $file_maps */
            public function __construct(
                private ByteReaderInterface $core_dump_file,
                private ProcessMemoryMap $process_memory_map,
                private array $file_maps,
            ) {
            }
            public function read(int $pid, int $remote_address, int $size): CData
            {
                $memory_areas = $this->process_memory_map->findByAddress($remote_address);
                if ($memory_areas === []) {
                    foreach ($this->file_maps as $file_map) {
                        if ($file_map->isInRange(UInt64::fromInt($remote_address))) {
                            $fp = fopen($file_map->name, 'rb');
                            if ($fp === false) {
                                throw new \RuntimeException("failed to open file: $file_map->name");
                            }
                            $offset = $remote_address - $file_map->start->toInt();
                            fseek(
                                $fp,
                                $file_map->file_offset->toInt() + $offset
                            );
                            $data = fread($fp, $size);
                            if ($data === false) {
                                throw new \RuntimeException("failed to read file: $file_map->name");
                            }
                            fclose($fp);
                            $cdata_buffer = \FFI::new("char[$size]");
                            if (is_null($cdata_buffer)) {
                                throw new \RuntimeException("failed to allocate memory");
                            }
                            \FFI::memcpy($cdata_buffer, $data, $size);
                            /** @var \FFI\CArray<int> */
                            return $cdata_buffer;
                        }
                    }
                    throw new \RuntimeException("no memory area found for address: " . dechex($remote_address));
                }
                $memory_area = $memory_areas[0];
                if ($memory_area->name === '') {
                    $offset = $remote_address - hexdec($memory_area->begin);
                    $data = $this->core_dump_file->createSliceAsString(
                        $offset + hexdec($memory_area->file_offset),
                        $size
                    );
                } else {
                    if ($memory_area->attribute->write) {
                        $offset = $remote_address - hexdec($memory_area->begin);
                        $data = $this->core_dump_file->createSliceAsString(
                            $offset + hexdec($memory_area->file_offset),
                            $size
                        );
                    } else {
                        $fp = fopen($memory_area->name, 'rb');
                        if ($fp === false) {
                            throw new \RuntimeException("failed to open file: $memory_area->name");
                        }
                        $offset = $remote_address - hexdec($memory_area->begin);
                        fseek(
                            $fp,
                            hexdec($memory_area->file_offset) + $offset
                        );
                        $data = fread($fp, $size);
                        if ($data === false) {
                            throw new \RuntimeException("failed to read file: $memory_area->name");
                        }
                        fclose($fp);
                    }
                }
                $cdata_buffer = \FFI::new("char[$size]");
                if (is_null($cdata_buffer)) {
                    throw new \RuntimeException("failed to allocate memory");
                }
                \FFI::memcpy($cdata_buffer, $data, $size);
                /** @var \FFI\CArray<int> */
                return $cdata_buffer;
            }
        };

        $container = $this->container_builder
            ->addDefinitions(
                require __DIR__ . '/../../../config/di.php'
            )
            ->addDefinitions([
                MemoryReaderInterface::class => $memory_reader,
                ProcessMemoryMapCreatorInterface::class =>
                    new class ($process_memory_map) implements ProcessMemoryMapCreatorInterface {
                        public function __construct(
                            private ProcessMemoryMap $process_memory_map,
                        ) {
                        }
                        public function getProcessMemoryMap(int $pid): ProcessMemoryMap
                        {
                            return $this->process_memory_map;
                        }
                    },
                ProcessPathResolver::class => autowire(MappedPathResolver::class)
                    ->constructorParameter('path_map', $path_mapping)
            ])
            ->build()
        ;
        return new CoreDumpReader(
            $container->get(PhpGlobalsFinder::class),
            $container->get(PhpVersionDetector::class),
            $container->get(MemoryLocationsCollector::class)
        );
    }
}
