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
 * Class Elf64Parser
 * @package PhpProfiler\Lib\Elf
 */
class Elf64Parser
{
    /**
     * @var BinaryReader
     */
    private BinaryReader $binary_reader;

    /**
     * Elf64Parser constructor.
     * @param BinaryReader $binary_reader
     */
    public function __construct(BinaryReader $binary_reader)
    {
        $this->binary_reader = $binary_reader;
    }

    /**
     * @param string $data
     * @return Elf64Header
     */
    public function parseElfHeader(string $data): Elf64Header
    {
        $header = new Elf64Header();
        $header->e_ident = [
            $this->binary_reader->read8($data, 0),
            $this->binary_reader->read8($data, 1),
            $this->binary_reader->read8($data, 2),
            $this->binary_reader->read8($data, 3),
            $this->binary_reader->read8($data, 4),
            $this->binary_reader->read8($data, 5),
            $this->binary_reader->read8($data, 6),
            $this->binary_reader->read8($data, 7),
            $this->binary_reader->read8($data, 8),
            $this->binary_reader->read8($data, 9),
        ];
        $header->e_type = $this->binary_reader->read16($data, 16);
        $header->e_machine = $this->binary_reader->read16($data, 18);
        $header->e_version = $this->binary_reader->read32($data, 20);
        $header->e_entry = $this->binary_reader->read64($data, 24);
        $header->e_phoff = $this->binary_reader->read64($data, 32);
        $header->e_shoff = $this->binary_reader->read64($data, 40);
        $header->e_flags = $this->binary_reader->read32($data, 48);
        $header->e_ehsize = $this->binary_reader->read16($data, 52);
        $header->e_phentsize = $this->binary_reader->read16($data, 54);
        $header->e_phnum = $this->binary_reader->read16($data, 56);
        $header->e_shentsize = $this->binary_reader->read16($data, 58);
        $header->e_shnum = $this->binary_reader->read16($data, 60);
        $header->e_shstrndx = $this->binary_reader->read16($data, 62);
        return $header;
    }
}