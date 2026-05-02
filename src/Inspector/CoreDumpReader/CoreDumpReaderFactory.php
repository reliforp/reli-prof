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
use Reli\Lib\Elf\Structure\Elf64\Elf64PrStatus;
use Reli\Lib\Elf\Structure\Elf64\NtFileEntry;
use Reli\Lib\Elf\Process\ProcessModuleSymbolReaderCreator;
use Reli\Lib\Elf\Tls\CoreDumpThreadPointerRetriever;
use Reli\Lib\Elf\Tls\ThreadPointerRetrieverInterface;
use Reli\Lib\File\PathResolver\MappedPathResolver;
use Reli\Lib\File\PathResolver\ProcessPathResolver;
use Reli\Lib\Integer\UInt64;
use Reli\Lib\PhpProcessReader\MainExecutable\MainExecutablePathResolver;
use Reli\Lib\PhpProcessReader\MainExecutable\StaticMainExecutablePathResolver;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocationsCollector;
use Reli\Lib\PhpProcessReader\PhpVersionDetector;
use Reli\Lib\Process\MemoryMap\ProcessMemoryArea;
use Reli\Lib\Process\MemoryMap\ProcessMemoryAttribute;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMap;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreatorInterface;
use Reli\Lib\Process\MemoryReader\MemoryReaderException;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;
use Reli\Lib\FFI\FFIHelper;

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
        /** @var Elf64PrStatus[] $pr_statuses */
        $pr_statuses = [];
        $file_maps = [];
        /** @var Elf64Note $note */
        foreach ($notes as $note) {
            if ($note->isCore()) {
                if ($note->isPrStatus()) {
                    $pr_statuses[] = $this->elf64_parser->parsePrStatus($note);
                }
            }
            if ($note->isFile()) {
                $file_maps = [
                    ...$file_maps,
                    ...$this->elf64_parser->parseNtFile($note)
                ];
            }
        }
        // Heuristic: the lowest-start named NT_FILE entry is treated as
        // the target's main executable, because /proc/<pid>/exe doesn't
        // exist post-mortem. On normal Linux PHP deployments — PIE main
        // binary, dynamic linker placing shared libraries much higher up
        // — the lowest-start entry is reliably the main binary, but this
        // is not a guarantee (statically-positioned binaries, special
        // loaders, or unusual mappings could violate it).
        //
        // We deliberately do *not* try to correlate against PT_LOAD here:
        // with `coredump_filter` 0x33 (gcore default) the main
        // executable's r-x text VMAs are excluded from PT_LOAD even
        // though they appear in NT_FILE. If the heuristic picks the wrong
        // file, downstream symbol resolution surfaces a clean failure
        // (`php module not found`, `cannot read ELF header from ...`)
        // rather than corrupting memory reads.
        //
        // A more principled implementation would parse NT_AUXV and use
        // AT_EXECFN, but the auxv string lives at a target-process
        // address that has to be resolved through `memory_areas`, which
        // makes it a bigger change than this PR is scoped for.
        $main_executable_path = null;
        $main_executable_vaddr = null;
        /** @var NtFileEntry $file_map */
        foreach ($file_maps as $file_map) {
            if ($file_map->name === '') {
                continue;
            }
            $start = $file_map->start->toInt();
            if ($main_executable_vaddr === null || $start < $main_executable_vaddr) {
                $main_executable_vaddr = $start;
                $main_executable_path = $file_map->name;
            }
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

        // NT_FILE entries enumerate every file-backed VMA the kernel
        // saw, including text (`r-x`) ones that `coredump_filter` may
        // exclude from PT_LOAD by default. Without those, ELF symbol
        // resolution against shared libraries (`.dynsym` reads, etc.)
        // throws OutOfBoundsException because the address isn't in
        // any tracked memory area. Add a synthetic memory area for
        // every NT_FILE range not already covered by a PT_LOAD-derived
        // entry so the MemoryReader can fall back to reading the file
        // via `--dependency-root`. The synthetic permissions are set
        // to `r-x` because the missing ranges are text segments in
        // practice — the dumper / readers don't gate on the bits.
        //
        // The "covered" check rejects on *any* overlap rather than
        // strict containment: kernel-written PT_LOAD and NT_FILE both
        // walk VMAs, so the normal shape is "PT_LOAD == NT_FILE" or
        // "one without the other". A partially-overlapping pair would
        // be a sign of unusual VMA merging upstream, and we'd rather
        // skip the synthetic entry (preferring the PT_LOAD-derived
        // record that already carries the right coredump offset) than
        // emit a duplicate that `findByAddress` could pick instead.
        foreach ($file_maps as $file_map) {
            if ($file_map->name === '') {
                continue;
            }
            $fm_start = $file_map->start->toInt();
            $fm_end = $file_map->end->toInt();
            $covered = false;
            foreach ($memory_areas as $area) {
                $a_begin = (int)hexdec($area->begin);
                $a_end = (int)hexdec($area->end);
                if ($a_begin < $fm_end && $fm_start < $a_end) {
                    $covered = true;
                    break;
                }
            }
            if ($covered) {
                continue;
            }
            // Resolve through MappedPathResolver before stat'ing so the
            // `--dependency-root` path is consulted. Without this the
            // target-view path (e.g. `/usr/lib/x86_64-linux-gnu/libc.so.6`
            // on the host running the analyser) is unlikely to exist
            // and inode falls back to 0, weakening the binary
            // fingerprint and pessimising the symbol cache.
            $resolved_path = $path_resolver->resolve(0, $file_map->name);
            $inode_result = file_exists($resolved_path) ? fileinode($resolved_path) : false;
            $synthetic_inode = $inode_result !== false ? $inode_result : 0;
            $memory_areas[] = new ProcessMemoryArea(
                dechex($fm_start),
                dechex($fm_end),
                dechex($file_map->file_offset->toInt()),
                new ProcessMemoryAttribute(true, false, true, false),
                '00:00',
                $synthetic_inode,
                $file_map->name,
            );
        }

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

            /** @var array<string, int> path => cached fd */
            private array $fd_cache = [];

            public function __destruct()
            {
                foreach ($this->fd_cache as $fd) {
                    $this->libc_ffi->close($fd);
                }
            }

            private function readFile(string $path, int $offset, int $size): ?string
            {
                if (!isset($this->fd_cache[$path])) {
                    /** @var int $fd */
                    $fd = $this->libc_ffi->open($path, 0); // O_RDONLY = 0
                    if ($fd < 0) {
                        return null;
                    }
                    $this->fd_cache[$path] = $fd;
                }
                $fd = $this->fd_cache[$path];
                $this->libc_ffi->lseek($fd, $offset, 0); // SEEK_SET = 0
                $buf = FFIHelper::new("unsigned char[$size]");
                /** @var int $read_len */
                $read_len = $this->libc_ffi->read($fd, $buf, $size);
                if ($read_len < $size) {
                    return null;
                }
                return \FFI::string($buf, $size);
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
                            $cdata_buffer = FFIHelper::new("unsigned char[$size]");
                            \FFI::memcpy($cdata_buffer, $data, $size);
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
                $cdata_buffer = FFIHelper::new("unsigned char[$size]");
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
                        #[\Override]
                        public function getProcessMemoryMap(int $pid): ProcessMemoryMap
                        {
                            return $this->process_memory_map;
                        }
                    },
                ProcessPathResolver::class => autowire(MappedPathResolver::class)
                    ->constructorParameter('path_map', $path_mapping),
                ProcessModuleSymbolReaderCreator::class =>
                    autowire(ProcessModuleSymbolReaderCreator::class)
                        ->constructorParameter(
                            'thread_pointer_retriever',
                            new CoreDumpThreadPointerRetriever($pr_statuses)
                        ),
                MainExecutablePathResolver::class =>
                    new StaticMainExecutablePathResolver($main_executable_path),
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
