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

namespace Reli\Lib\Elf\Process;

use Reli\Lib\ByteStream\IntegerByteSequence\IntegerByteSequenceReader;
use Reli\Lib\Elf\Parser\ElfParserException;
use Reli\Lib\Elf\SymbolResolver\Elf64CachedSymbolResolver;
use Reli\Lib\Elf\SymbolResolver\SymbolResolverCreatorInterface;
use Reli\Lib\Elf\Tls\Aarch64LinuxThreadPointerRetriever;
use Reli\Lib\Elf\Tls\LibThreadDbTlsFinder;
use Reli\Lib\Elf\Tls\MuslTlsFinder;
use Reli\Lib\Elf\Tls\ThreadPointerRetrieverInterface;
use Reli\Lib\Elf\Tls\TlsFinderException;
use Reli\Lib\Elf\Tls\X64LinuxThreadPointerRetriever;
use Reli\Lib\System\Architecture;
use Reli\Lib\File\PathResolver\ProcessPathResolver;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMap;
use Reli\Lib\Process\MemoryMap\ProcessModuleMemoryMap;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;

final class ProcessModuleSymbolReaderCreator implements ProcessModuleSymbolReaderCreatorInterface
{
    public function __construct(
        private SymbolResolverCreatorInterface $symbol_resolver_creator,
        private MemoryReaderInterface $memory_reader,
        private PerBinarySymbolCacheRetriever $per_binary_symbol_cache_retriever,
        private IntegerByteSequenceReader $integer_reader,
        private LinkMapLoader $link_map_loader,
        private ProcessPathResolver $process_path_resolver,
        private BinaryAnalysisCache $binary_analysis_cache,
        private ?ThreadPointerRetrieverInterface $thread_pointer_retriever = null,
        private ?BinaryFingerprintCreator $binary_fingerprint_creator = null,
    ) {
    }

    private function fingerprint(int $pid, ProcessModuleMemoryMap $module_memory_map): BinaryFingerprint
    {
        if ($this->binary_fingerprint_creator !== null) {
            return $this->binary_fingerprint_creator->createFromProcessModuleMemoryMap(
                $pid,
                $module_memory_map,
            );
        }
        return BinaryFingerprint::fromProcessModuleMemoryMap($module_memory_map);
    }

    #[\Override]
    public function createModuleReaderByNameRegex(
        int $pid,
        ProcessMemoryMap $process_memory_map,
        string $regex,
        ?string $binary_path,
        ?ProcessModuleSymbolReader $libpthread_symbol_reader = null,
        ?int $root_link_map_address = null,
    ): ?ProcessModuleSymbolReader {
        $memory_areas = $process_memory_map->findByNameRegex($regex);
        if ($memory_areas === []) {
            return null;
        }
        $module_memory_map = new ProcessModuleMemoryMap($memory_areas);

        $module_name = $module_memory_map->getModuleName();
        $path = $binary_path ?? $this->process_path_resolver->resolve($pid, $module_name);

        // Use the strong fingerprint (device_id + inode + module_name + ELF
        // header bytes) rather than the weak fingerprint
        // (BinaryFingerprint::fromProcessModuleMemoryMap, which is just
        // device_id + inode + name). The weak fingerprint can collide
        // across different binaries in container environments where
        // overlayfs reassigns device_id / inode. Now that resolver_cache
        // caches the parsed Elf64SymbolResolver per binary fingerprint, a
        // collision means a different binary's symbol table gets handed
        // back, yielding garbage st_value addresses, garbage process_vm_readv
        // reads, and zend_mm_heap corruption when MemoryDumper walks the
        // wrong region. (Bisect probe: ARM64 CI failure reproduces in
        // MemoryDumpCommandTest / MemoryCompareCommandIntegrationTest.)
        $binary_fingerprint = $this->fingerprint($pid, $module_memory_map);
        $symbol_resolver = new Elf64CachedSymbolResolver(
            new Elf64LazyParseSymbolResolver(
                $path,
                $this->memory_reader,
                $pid,
                $module_memory_map,
                $this->symbol_resolver_creator,
                $this->per_binary_symbol_cache_retriever,
                $binary_fingerprint,
            ),
            $this->per_binary_symbol_cache_retriever->get($binary_fingerprint),
            $this->binary_analysis_cache,
            $binary_fingerprint,
        );

        $tls_block_address = null;
        if (!is_null($libpthread_symbol_reader) and !is_null($root_link_map_address)) {
            try {
                $libpthread_memory_areas = $process_memory_map->findByNameRegex('/libpthread/');
                $libpthread_fingerprint = null;
                if ($libpthread_memory_areas !== []) {
                    $libpthread_module_map = new ProcessModuleMemoryMap($libpthread_memory_areas);
                    $libpthread_fingerprint = $this->fingerprint($pid, $libpthread_module_map);
                }
                $thread_pointer_retriever = $this->thread_pointer_retriever
                    ?? match (Architecture::detect()) {
                        Architecture::X86_64 => X64LinuxThreadPointerRetriever::createDefault(),
                        Architecture::AARCH64 => Aarch64LinuxThreadPointerRetriever::createDefault(),
                    };
                $tls_finder = new LibThreadDbTlsFinder(
                    $libpthread_symbol_reader,
                    $thread_pointer_retriever,
                    $this->memory_reader,
                    $this->integer_reader,
                    $this->binary_analysis_cache,
                    $libpthread_fingerprint,
                );
                $link_map = $this->link_map_loader->searchByName(
                    $module_name,
                    $pid,
                    $root_link_map_address,
                );
                $tls_block_address = $tls_finder->findTlsBlock($pid, $link_map?->this_address);
            } catch (TlsFinderException | \Reli\Lib\Process\MemoryReader\MemoryReaderException) {
                // glibc path failed — try musl fallback
                // musl has no libthread_db.so; detect via /lib/ld-musl-* in maps
                $is_musl = $process_memory_map->findByNameRegex('ld-musl') !== [];
                if ($is_musl) {
                    try {
                        $thread_pointer_retriever ??= match (Architecture::detect()) {
                            Architecture::X86_64 => X64LinuxThreadPointerRetriever::createDefault(),
                            Architecture::AARCH64 => Aarch64LinuxThreadPointerRetriever::createDefault(),
                        };
                        $musl_tls_finder = new MuslTlsFinder(
                            $thread_pointer_retriever,
                            $this->memory_reader,
                            $this->integer_reader,
                        );
                        $tls_block_address = $musl_tls_finder->findTlsBlock($pid, null);
                    } catch (TlsFinderException | \Reli\Lib\Process\MemoryReader\MemoryReaderException) {
                    }
                }
            }
        }

        return new ProcessModuleSymbolReader(
            $pid,
            $symbol_resolver,
            $module_memory_map,
            $this->memory_reader,
            $this->integer_reader,
            $tls_block_address
        );
    }
}
