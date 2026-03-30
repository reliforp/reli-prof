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
use FFI;
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
use Reli\Lib\Process\MemoryReader\MemoryReaderException;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;

use function dechex;
use function DI\autowire;
use function fclose;
use function fopen;
use function fread;
use function fseek;

final class CoreDumpReaderFactory
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
        /** @var array<string, int> $coredump_offsets vaddr_hex => coredump file offset */
        $coredump_offsets = [];
        foreach ($load_segments as $load_segment) {
            $corresponding_file = null;
            /** @var NtFileEntry $file_map */
            foreach ($file_maps as $file_map) {
                if ($file_map->isInRange($load_segment->p_vaddr)) {
                    $corresponding_file = $file_map;
                    break;
                }
            }

            $vaddr_hex = dechex($load_segment->p_vaddr->toInt());

            // Always store the coredump p_offset for reading data from the coredump
            $coredump_offsets[$vaddr_hex] = $load_segment->p_offset->toInt();

            // For ProcessMemoryArea, use the original file offset (matching /proc/pid/maps)
            // so that ProcessModuleMemoryMap::getMemoryAddressFromOffset() works correctly
            if ($corresponding_file !== null) {
                $file_offset = $corresponding_file->file_offset->toInt();
            } else {
                // Anonymous memory (heap, BSS, etc.) - use 0 like /proc/pid/maps
                $file_offset = 0;
            }

            $file_path = $corresponding_file?->name ?? '';
            $inode_result = $file_path !== '' && file_exists($file_path) ? fileinode($file_path) : false;
            $file_inode = $inode_result !== false ? $inode_result : 0;

            $memory_areas[] = new ProcessMemoryArea(
                $vaddr_hex,
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
        $path_resolver = new MappedPathResolver($path_mapping);
        $process_memory_map = new ProcessMemoryMap($memory_areas);
        /** @var FFI&object{open:callable,lseek:callable,read:callable,close:callable} $libc_ffi */
        $libc_ffi = FFI::cdef('
            int open(const char *pathname, int flags);
            off_t lseek(int fd, off_t offset, int whence);
            ssize_t read(int fd, void *buf, size_t count);
            int close(int fd);
        ');
        $memory_reader = new class (
            $binary,
            $process_memory_map,
            $file_maps,
            $path_resolver,
            $coredump_offsets,
            $libc_ffi
        ) implements MemoryReaderInterface {
            /**
             * @param NtFileEntry[] $file_maps
             * @param array<string, int> $coredump_offsets
             */
            public function __construct(
                private ByteReaderInterface $core_dump_file,
                private ProcessMemoryMap $process_memory_map,
                private array $file_maps,
                private MappedPathResolver $path_resolver,
                private array $coredump_offsets,
                private FFI $libc_ffi,
            ) {
            }

            private function readFile(string $path, int $offset, int $size): ?string
            {
                /** @var int $fd */
                $fd = $this->libc_ffi->open($path, 0); // O_RDONLY = 0
                if ($fd < 0) {
                    return null;
                }
                $this->libc_ffi->lseek($fd, $offset, 0); // SEEK_SET = 0
                $buf = FFI::new("unsigned char[$size]");
                if (is_null($buf)) {
                    $this->libc_ffi->close($fd);
                    return null;
                }
                /** @var int $read_len */
                $read_len = $this->libc_ffi->read($fd, $buf, $size);
                $this->libc_ffi->close($fd);
                if ($read_len < $size) {
                    return null;
                }
                return FFI::string($buf, $size);
            }
            #[\Override]
            public function read(int $pid, int $remote_address, int $size): CData
            {
                $memory_areas = $this->process_memory_map->findByAddress($remote_address);
                if ($memory_areas === []) {
                    foreach ($this->file_maps as $file_map) {
                        if ($file_map->isInRange(UInt64::fromInt($remote_address))) {
                            $resolved_name = $this->path_resolver->resolve($pid, $file_map->name);
                            $offset = $remote_address - $file_map->start->toInt();
                            $data = $this->readFile(
                                $resolved_name,
                                $file_map->file_offset->toInt() + $offset,
                                $size
                            );
                            if ($data === null) {
                                continue;
                            }
                            $cdata_buffer = FFI::new("unsigned char[$size]");
                            if (is_null($cdata_buffer)) {
                                throw new \RuntimeException("failed to allocate memory");
                            }
                            FFI::memcpy($cdata_buffer, $data, $size);
                            /** @var \FFI\CArray<int> */
                            return $cdata_buffer;
                        }
                    }
                    throw new MemoryReaderException("no memory area found for address: " . dechex($remote_address));
                }
                $memory_area = $memory_areas[0];
                $coredump_offset = $this->coredump_offsets[$memory_area->begin] ?? null;
                if ($coredump_offset !== null) {
                    // Data is available in the coredump: always prefer it over the
                    // original file, because the coredump captures runtime state
                    // (e.g., ld-linux writes to DT_DEBUG in read-only CoW pages).
                    $offset = $remote_address - hexdec($memory_area->begin);
                    $data = $this->core_dump_file->createSliceAsString(
                        $offset + $coredump_offset,
                        $size
                    );
                } elseif ($memory_area->name !== '') {
                    // No coredump data: fall back to original file via path resolver
                    $resolved_path = $this->path_resolver->resolve($pid, $memory_area->name);
                    $offset = $remote_address - hexdec($memory_area->begin);
                    $data = $this->readFile(
                        $resolved_path,
                        (int)hexdec($memory_area->file_offset) + $offset,
                        $size
                    );
                    if ($data === null) {
                        throw new \RuntimeException(
                            "failed to read file: $memory_area->name (resolved: $resolved_path)"
                        );
                    }
                } else {
                    throw new \RuntimeException(
                        "no coredump data and no file for memory area: " . $memory_area->begin
                    );
                }
                $cdata_buffer = FFI::new("unsigned char[$size]");
                if (is_null($cdata_buffer)) {
                    throw new \RuntimeException("failed to allocate memory");
                }
                FFI::memcpy($cdata_buffer, $data, $size);
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
                        #[\Override]
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
