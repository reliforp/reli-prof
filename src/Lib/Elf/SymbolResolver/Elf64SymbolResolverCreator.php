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

use PhpProfiler\Lib\Binary\BinaryReader;
use PhpProfiler\Lib\Binary\ProcessMemoryByteReader;
use PhpProfiler\Lib\Binary\StringByteReader;
use PhpProfiler\Lib\Binary\UnrelocatedProcessMemoryByteReader;
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
    private FileReaderInterface $file_reader;

    /**
     * SymbolResolverCreator constructor.
     * @param FileReaderInterface $file_reader
     */
    public function __construct(FileReaderInterface $file_reader)
    {
        $this->file_reader = $file_reader;
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
        $parser = new Elf64Parser(new BinaryReader());
        $elf_header = $parser->parseElfHeader($binary);
        $section_header = $parser->parseSectionHeader($binary, $elf_header);
        $symbol_table_section_header_entry = $section_header->findSymbolTableEntry();
        $string_table_section_header_entry = $section_header->findStringTableEntry();
        if (is_null($symbol_table_section_header_entry)) {
            throw new ElfParserException('cannot find symbol table from section header table');
        }
        if (is_null($string_table_section_header_entry)) {
            throw new ElfParserException('cannot find string table from section header table');
        }
        $symbol_table = $parser->parseSymbolTableFromSectionHeader($binary, $symbol_table_section_header_entry);
        $string_table = $parser->parseStringTableFromSectionHeader($binary, $string_table_section_header_entry);
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
        $parser = new Elf64Parser(new BinaryReader());
        return Elf64DynamicSymbolResolver::load($parser, $binary);
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

        $parser = new Elf64Parser(new BinaryReader());
        $elf_header = $parser->parseElfHeader($unrelocated_php_binary);
        $elf_program_header = $parser->parseProgramHeader($unrelocated_php_binary, $elf_header);
        $elf_dynamic_array = $parser->parseDynamicStructureArray(
            $unrelocated_php_binary,
            $elf_program_header->findDynamic()[0]
        );

        $elf_string_table = $parser->parseStringTable($php_binary, $elf_dynamic_array);
        $elf_gnu_hash_table = $parser->parseGnuHashTable($php_binary, $elf_dynamic_array);
        if (is_null($elf_gnu_hash_table)) {
            throw new ElfParserException('cannot find gnu hash table');
        }
        $elf_symbol_table = $parser->parseSymbolTableFromDynamic(
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
