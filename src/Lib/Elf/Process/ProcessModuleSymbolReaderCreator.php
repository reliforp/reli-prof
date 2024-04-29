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
use Reli\Lib\Elf\Tls\LibThreadDbTlsFinder;
use Reli\Lib\Elf\Tls\TlsFinderException;
use Reli\Lib\Elf\Tls\X64LinuxThreadPointerRetriever;
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
    ) {
    }

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
		if (file_exists($path) === false) {
			return null;
		}

        $symbol_resolver = new Elf64CachedSymbolResolver(
            new Elf64LazyParseSymbolResolver(
                $path,
                $this->memory_reader,
                $pid,
                $module_memory_map,
                $this->symbol_resolver_creator,
            ),
            $this->per_binary_symbol_cache_retriever->get(
                BinaryFingerprint::fromProcessModuleMemoryMap($module_memory_map)
            ),
        );

        $tls_block_address = null;
        if (!is_null($libpthread_symbol_reader) and !is_null($root_link_map_address)) {
            try {
                $tls_finder = new LibThreadDbTlsFinder(
                    $libpthread_symbol_reader,
                    X64LinuxThreadPointerRetriever::createDefault(),
                    $this->memory_reader,
                    $this->integer_reader
                );
                $link_map = $this->link_map_loader->searchByName(
                    $module_name,
                    $pid,
                    $root_link_map_address,
                );
                $tls_block_address = $tls_finder->findTlsBlock($pid, $link_map?->this_address);
            } catch (TlsFinderException $e) {
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
