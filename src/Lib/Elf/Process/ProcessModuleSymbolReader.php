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

use FFI\CData;
use Reli\Lib\ByteStream\CDataByteReader;
use Reli\Lib\ByteStream\IntegerByteSequence\IntegerByteSequenceReader;
use Reli\Lib\Elf\SymbolResolver\Elf64AllSymbolResolver;
use Reli\Lib\Elf\SymbolResolver\Elf64SymbolResolver;
use Reli\Lib\Process\MemoryMap\ProcessModuleMemoryMap;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;
use Reli\Lib\Process\MemoryReader\MemoryReaderException;

use function is_null;

final class ProcessModuleSymbolReader implements ProcessSymbolReaderInterface
{
    private int $base_address;

    public function __construct(
        private int $pid,
        private Elf64SymbolResolver $symbol_resolver,
        ProcessModuleMemoryMap $module_memory_map,
        private MemoryReaderInterface $memory_reader,
        private IntegerByteSequenceReader $integer_reader,
        private ?int $tls_block_address
    ) {
        $this->base_address = $module_memory_map->getBaseAddress() - $this->symbol_resolver->getBaseAddress()->toInt();
    }

    /**
     * @return \FFI\CArray<int>|null
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
     * @return array{int, int}|null
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

    public function getLinkMapAddress(): ?int
    {
        $dt_debug_address = $this->symbol_resolver->getDtDebugAddress();
        if (is_null($dt_debug_address)) {
            return null;
        }
        $dt_debug_un_pointer = $this->base_address + $dt_debug_address + 8;
        $r_debug_pointer = $this->integer_reader->read64(
            new CDataByteReader(
                $this->memory_reader->read(
                    $this->pid,
                    $dt_debug_un_pointer,
                    8
                ),
            ),
            0,
        )->toInt();
        $root_link_map_address_pointer =  $r_debug_pointer + 8;
        $root_link_map_address = $this->integer_reader->read64(
            new CDataByteReader(
                $this->memory_reader->read(
                    $this->pid,
                    $root_link_map_address_pointer,
                    8
                ),
            ),
            0,
        )->toInt();
        if ($root_link_map_address === 0) {
            return null;
        }
        return $root_link_map_address;
    }

    public function isAllSymbolResolvable(): bool
    {
        return $this->symbol_resolver instanceof Elf64AllSymbolResolver;
    }
}
