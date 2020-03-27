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

/**
 * Class Elf64SymbolResolver
 * @package PhpProfiler\Lib\Elf
 */
class Elf64SymbolResolver
{
    /**
     * @var Elf64SymbolTable
     */
    private Elf64SymbolTable $symbol_table;
    /**
     * @var Elf64GnuHashTable
     */
    private Elf64GnuHashTable $hash_table;
    /**
     * @var Elf64StringTable
     */
    private Elf64StringTable $string_table;


    /**
     * @param Elf64Parser $parser
     * @param string $php_binary
     * @return Elf64SymbolResolver
     */
    public static function load(Elf64Parser $parser, string $php_binary): self
    {
        $elf_header = $parser->parseElfHeader($php_binary);
        $elf_program_header = $parser->parseProgramHeader($php_binary, $elf_header);
        $elf_dynamic_array = $parser->parseDynamicStructureArray($php_binary, $elf_program_header->findDynamic()[0]);
        $elf_string_table = $parser->parseStringTable($php_binary, $elf_dynamic_array);
        $elf_gnu_hash_table = $parser->parseGnuHashTable($php_binary, $elf_dynamic_array);
        $elf_symbol_table = $parser->parseSymbolTable($php_binary, $elf_dynamic_array, $elf_gnu_hash_table->getNumberOfSymbols());
        return new self(
            $elf_symbol_table,
            $elf_gnu_hash_table,
            $elf_string_table
        );
    }

    /**
     * Elf64SymbolResolver constructor.
     * @param Elf64SymbolTable $symbol_table
     * @param Elf64GnuHashTable $hash_table
     * @param Elf64StringTable $string_table
     */
    public function __construct(
        Elf64SymbolTable $symbol_table,
        Elf64GnuHashTable $hash_table,
        Elf64StringTable $string_table
    ){
        $this->symbol_table = $symbol_table;
        $this->hash_table = $hash_table;
        $this->string_table = $string_table;
    }

    /**
     * @param string $symbol_name
     * @return Elf64SymbolTableEntry
     */
    public function resolve(string $symbol_name): Elf64SymbolTableEntry
    {
        $index = $this->hash_table->lookup($symbol_name, function (string $name, int $index) {
            $symbol = $this->symbol_table->entries[$index];
            return $name === $this->string_table->lookup($symbol->st_name);
        });
        return $this->symbol_table->entries[$index];
    }
}