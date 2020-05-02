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
use PhpProfiler\Lib\Binary\StringByteReader;
use PhpProfiler\Lib\Elf\Parser\Elf64Parser;
use PhpProfiler\Lib\Elf\Parser\ElfParserException;

/**
 * Class SymbolResolverCreator
 * @package PhpProfiler\Lib\Elf
 */
final class SymbolResolverCreator
{
    /**
     * @param string $path
     * @return Elf64LinearScanSymbolResolver
     * @throws ElfParserException
     */
    public function createLinearScanResolverFromPath(string $path): Elf64LinearScanSymbolResolver
    {
        $binary = new StringByteReader(file_get_contents($path));
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
        $binary = new StringByteReader(file_get_contents($path));
        $parser = new Elf64Parser(new BinaryReader());
        return Elf64DynamicSymbolResolver::load($parser, $binary);
    }
}
