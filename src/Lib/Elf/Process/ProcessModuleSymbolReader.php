<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Lib\Elf\Process;

use FFI\CData;
use PhpProfiler\Lib\Elf\SymbolResolver\Elf64AllSymbolResolver;
use PhpProfiler\Lib\Elf\SymbolResolver\Elf64SymbolResolver;
use PhpProfiler\Lib\Process\MemoryMap\ProcessModuleMemoryMap;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderInterface;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderException;

/**
 * Class ProcessModuleSymbolReader
 * @package PhpProfiler\ProcessReader
 */
final class ProcessModuleSymbolReader implements ProcessSymbolReaderInterface
{
    private int $base_address;

    /**
     * ProcessModuleSymbolResolver constructor.
     */
    public function __construct(
        private int $pid,
        private Elf64SymbolResolver $symbol_resolver,
        private ProcessModuleMemoryMap $module_memory_map,
        private MemoryReaderInterface $memory_reader,
        private ?int $tls_block_address
    ) {
        $this->base_address = $module_memory_map->getBaseAddress();
    }

    /**
     * @param string $symbol_name
     * @return \FFI\CArray|null
     * @throws MemoryReaderException
     * @throws ProcessSymbolReaderException
     */
    public function read(string $symbol_name): ?CData
    {
        $address_and_size = $this->resolveAddressAndSize($symbol_name);
        if ($address_and_size === null) {
            return null;
        }
        [$address, $size] = $address_and_size;
        return $this->memory_reader->read($this->pid, $address, $size);
    }

    /**
     * @param string $symbol_name
     * @return int|null
     * @throws ProcessSymbolReaderException
     */
    public function resolveAddress(string $symbol_name): ?int
    {
        $address_and_size = $this->resolveAddressAndSize($symbol_name);
        if ($address_and_size === null) {
            return null;
        }
        [$address,] = $address_and_size;
        return $address;
    }


    /**
     * @param string $symbol_name
     * @return int[]|null
     * @throws ProcessSymbolReaderException
     */
    private function resolveAddressAndSize(string $symbol_name): ?array
    {
        $symbol = $this->symbol_resolver->resolve($symbol_name);
        if ($symbol->isUndefined()) {
            return null;
        }
        $base_address = $this->base_address;

        if ($symbol->isTls()) {
            if (is_null($this->tls_block_address)) {
                throw new ProcessSymbolReaderException(
                    'trying to resolve TLS symbol but cannot find TLS block address'
                );
            }
            $base_address = $this->tls_block_address;
        }
        return [$base_address + $symbol->st_value->toInt(), $symbol->st_size->toInt()];
    }

    /**
     * @return bool
     */
    public function isAllSymbolResolvable(): bool
    {
        return $this->symbol_resolver instanceof Elf64AllSymbolResolver;
    }
}
