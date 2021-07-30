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

namespace PhpProfiler\Lib\Elf\SymbolResolver;

use PhpProfiler\Lib\ByteStream\ProcessMemoryByteReader;
use PhpProfiler\Lib\ByteStream\StringByteReader;
use PhpProfiler\Lib\ByteStream\UnrelocatedProcessMemoryByteReader;
use PhpProfiler\Lib\Elf\Parser\Elf64Parser;
use PhpProfiler\Lib\Elf\Parser\ElfParserException;
use PhpProfiler\Lib\File\FileReaderInterface;
use PhpProfiler\Lib\Process\MemoryMap\ProcessModuleMemoryMapInterface;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderInterface;

/**
 * Class SymbolResolverCreator
 * @package PhpProfiler\Lib\Elf
 */
final class Elf64SymbolResolverCreator implements SymbolResolverCreatorInterface
{
    /**
     * SymbolResolverCreator constructor.
     */
    public function __construct(
        private FileReaderInterface $file_reader,
        private Elf64Parser $elf_parser,
    ) {
    }

    /**
     * @param string $path
     * @return Elf64LinearScanSymbolResolver
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
        return new Elf64LinearScanSymbolResolver($symbol_table, $string_table);
    }

    /**
     * @param string $path
     * @return Elf64DynamicSymbolResolver
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
     * @param MemoryReaderInterface $memory_reader
     * @param int $pid
     * @param ProcessModuleMemoryMapInterface $module_memory_map
     * @return Elf64DynamicSymbolResolver
     * @throws ElfParserException
     */
    public function createDynamicResolverFromProcessMemory(
        MemoryReaderInterface $memory_reader,
        int $pid,
        ProcessModuleMemoryMapInterface $module_memory_map
    ): Elf64DynamicSymbolResolver {
        $php_binary = new ProcessMemoryByteReader($memory_reader, $pid, $module_memory_map);
        $unrelocated_php_binary = new UnrelocatedProcessMemoryByteReader($php_binary, $module_memory_map);

        $elf_header = $this->elf_parser->parseElfHeader($unrelocated_php_binary);
        $elf_program_header = $this->elf_parser->parseProgramHeader($unrelocated_php_binary, $elf_header);
        $elf_dynamic_array = $this->elf_parser->parseDynamicStructureArray(
            $unrelocated_php_binary,
            $elf_program_header->findDynamic()[0]
        );

        $elf_string_table = $this->elf_parser->parseStringTable($php_binary, $elf_dynamic_array);
        $elf_gnu_hash_table = $this->elf_parser->parseGnuHashTable($php_binary, $elf_dynamic_array);
        if (is_null($elf_gnu_hash_table)) {
            throw new ElfParserException('cannot find gnu hash table');
        }
        $elf_symbol_table = $this->elf_parser->parseSymbolTableFromDynamic(
            $php_binary,
            $elf_dynamic_array,
            $elf_gnu_hash_table->getNumberOfSymbols()
        );
        return new Elf64DynamicSymbolResolver(
            $elf_symbol_table,
            $elf_gnu_hash_table,
            $elf_string_table
        );
    }
}
