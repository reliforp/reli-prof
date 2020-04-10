<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */


namespace PhpProfiler\ProcessReader;

use PhpProfiler\Lib\Elf\Elf64SymbolResolver;
use PhpProfiler\Lib\Process\MemoryReader;

/**
 * Class ProcessModuleSymbolReader
 * @package PhpProfiler\ProcessReader
 */
class ProcessModuleSymbolReader
{
    private Elf64SymbolResolver $symbol_resolver;
    /** @var ProcessMemoryArea[] */
    private array $memory_areas;
    private int $base_address;
    private MemoryReader $memory_reader;
    private ?int $tls_block_address;
    private int $pid;

    /**
     * ProcessModuleSymbolResolver constructor.
     * @param int $pid
     * @param Elf64SymbolResolver $symbol_resolver
     * @param ProcessMemoryArea[] $memory_areas
     * @param MemoryReader $memory_reader
     * @param int|null $tls_block_address
     */
    public function __construct(
        int $pid,
        Elf64SymbolResolver $symbol_resolver,
        array $memory_areas,
        MemoryReader $memory_reader,
        ?int $tls_block_address
    ) {
        $this->pid = $pid;
        $this->symbol_resolver = $symbol_resolver;
        $this->memory_areas = $memory_areas;
        $this->base_address = hexdec(current($memory_areas)->begin);
        $this->memory_reader = $memory_reader;
        $this->tls_block_address = $tls_block_address;
    }

    /**
     * @param string $symbol_name
     * @return mixed
     * @throws \PhpProfiler\Lib\Process\MemoryReaderException
     */
    public function read(string $symbol_name)
    {
        $symbol = $this->symbol_resolver->resolve($symbol_name);
        $base_address = !$symbol->isTls() ? $this->base_address : $this->tls_block_address;
        $address = $base_address + $symbol->st_value->toInt();
        return $this->memory_reader->read($this->pid, $address, $symbol->st_size->toInt());
    }

    /**
     * @param string $symbol_name
     * @return int|null
     * @throws \PhpProfiler\Lib\Process\MemoryReaderException
     */
    public function readAsInt64(string $symbol_name): ?int
    {
        $symbol = $this->symbol_resolver->resolve($symbol_name);
        if ($symbol->isUndefined()) {
            return null;
        }
        $base_address = !$symbol->isTls() ? $this->base_address : $this->tls_block_address;
        $address = $base_address + $symbol->st_value->toInt();
        return $this->memory_reader->readAsInt64($this->pid, $address);
    }
}