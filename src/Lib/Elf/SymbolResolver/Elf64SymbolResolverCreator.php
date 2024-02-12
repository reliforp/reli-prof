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

namespace Reli\Lib\Elf\SymbolResolver;

use Reli\Lib\ByteStream\ProcessMemoryByteReader;
use Reli\Lib\ByteStream\StringByteReader;
use Reli\Lib\ByteStream\UnrelocatedProcessMemoryByteReader;
use Reli\Lib\Elf\Parser\Elf64Parser;
use Reli\Lib\Elf\Parser\ElfParserException;
use Reli\Lib\File\FileReaderInterface;
use Reli\Lib\Process\MemoryMap\ProcessModuleMemoryMapInterface;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;

final class Elf64SymbolResolverCreator implements SymbolResolverCreatorInterface
{
    public function __construct(
        private FileReaderInterface $file_reader,
        private Elf64Parser $elf_parser,
    ) {
    }

    /**
     * @throws ElfParserException
     */
    public function createLinearScanResolverFromPath(string $path): Elf64LinearScanSymbolResolver
    {
        $binary_raw = $this->file_reader->readAll($path);
        if ($binary_raw === '') {
            throw new ElfParserException('cannot read ELF binary');
        }
        $binary = new StringByteReader($binary_raw);
        $elf_header = $this->elf_parser->parseElfHeader($binary);
        $program_header = $this->elf_parser->parseProgramHeader($binary, $elf_header);
        $base_address = $program_header->findBaseAddress();
        $section_header = $this->elf_parser->parseSectionHeader($binary, $elf_header);
        $symbol_table_section_header_entry = $section_header->findSymbolTableEntry();
        $string_table_section_header_entry = $section_header->findStringTableEntry();
        if (is_null($symbol_table_section_header_entry)) {
            throw new ElfParserException('cannot find symbol table from section header table');
        }
        if (is_null($string_table_section_header_entry)) {
            throw new ElfParserException('cannot find string table from section header table');
        }
        $symbol_table = $this->elf_parser->parseSymbolTableFromSectionHeader(
            $binary,
            $symbol_table_section_header_entry
        );
        $string_table = $this->elf_parser->parseStringTableFromSectionHeader(
            $binary,
            $string_table_section_header_entry
        );

        $dt_debug_address = null;
        $dynamic_entry = $section_header->findDynamicEntry();
        if (!is_null($dynamic_entry)) {
            $dynamic_array = $this->elf_parser->parseDynamicStructureArray(
                $binary,
                $dynamic_entry->sh_offset,
                $dynamic_entry->sh_addr
            );
            $debug_entry = $dynamic_array->findDebugEntry();
            if (!is_null($debug_entry)) {
                $dt_debug_address = $debug_entry->v_addr;
            }
        }

        return new Elf64LinearScanSymbolResolver(
            $symbol_table,
            $string_table,
            $base_address,
            $dt_debug_address,
        );
    }

    /**
     * @throws ElfParserException
     */
    public function createDynamicResolverFromPath(string $path): Elf64DynamicSymbolResolver
    {
        $binary_raw = $this->file_reader->readAll($path);
        if ($binary_raw === '') {
            throw new ElfParserException('cannot read ELF binary');
        }
        $binary = new StringByteReader($binary_raw);
        return Elf64DynamicSymbolResolver::load($this->elf_parser, $binary);
    }

    /**
     * @throws ElfParserException
     */
    public function createDynamicResolverFromProcessMemory(
        MemoryReaderInterface $memory_reader,
        int $pid,
        ProcessModuleMemoryMapInterface $module_memory_map
    ): Elf64DynamicSymbolResolver {
        $php_binary = new ProcessMemoryByteReader($memory_reader, $pid, $module_memory_map);

        $unrelocated_php_binary = $php_binary;

        $elf_header = $this->elf_parser->parseElfHeader($unrelocated_php_binary);
        $elf_program_header = $this->elf_parser->parseProgramHeader($unrelocated_php_binary, $elf_header);
        $dynamic_entry = $elf_program_header->findDynamic()[0];
        $elf_dynamic_array = $this->elf_parser->parseDynamicStructureArray(
            $unrelocated_php_binary,
            $dynamic_entry->p_offset,
            $dynamic_entry->p_vaddr
        );

        $base_address = $elf_program_header->findBaseAddress();
        $elf_string_table = $this->elf_parser->parseStringTable($php_binary, $base_address, $elf_dynamic_array);
        $elf_gnu_hash_table = $this->elf_parser->parseGnuHashTable($php_binary, $base_address, $elf_dynamic_array);
        if (is_null($elf_gnu_hash_table)) {
            throw new ElfParserException('cannot find gnu hash table');
        }
        $elf_symbol_table = $this->elf_parser->parseSymbolTableFromDynamic(
            $php_binary,
            $base_address,
            $elf_dynamic_array,
            $elf_gnu_hash_table->getNumberOfSymbols()
        );

        $link_map_pointer_address = null;
        $debug_entry = $elf_dynamic_array->findDebugEntry();
        if (!is_null($debug_entry)) {
            $link_map_pointer_address = $debug_entry->d_un->toInt() + 4;
        }

        return new Elf64DynamicSymbolResolver(
            $elf_symbol_table,
            $elf_gnu_hash_table,
            $elf_string_table,
            $base_address,
            $link_map_pointer_address
        );
    }
}
