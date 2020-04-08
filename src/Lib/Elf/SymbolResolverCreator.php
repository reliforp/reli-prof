<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */


namespace PhpProfiler\Lib\Elf;

use PhpProfiler\Lib\Binary\BinaryReader;

/**
 * Class SymbolResolverCreator
 * @package PhpProfiler\Lib\Elf
 */
class SymbolResolverCreator
{
    /**
     * @param string $path
     * @return Elf64LinearScanSymbolResolver
     */
    public function createLinearScanResolverFromPath(string $path): Elf64LinearScanSymbolResolver
    {
        $binary = file_get_contents($path);
        $parser = new Elf64Parser(new BinaryReader());
        $elf_header = $parser->parseElfHeader($binary);
        $section_header = $parser->parseSectionHeader($binary, $elf_header);
        $symbol_table = $parser->parseSymbolTableFromSectionHeader($binary, $section_header->findSymbolTableEntry());
        $string_table = $parser->parseStringTableFromSectionHeader($binary, $section_header->findStringTableEntry());
        return new Elf64LinearScanSymbolResolver($symbol_table, $string_table);
    }
}