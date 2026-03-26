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

namespace Reli\Lib\Dwarf;

use Reli\Lib\ByteStream\IntegerByteSequence\LittleEndianReader;
use Reli\Lib\ByteStream\StringByteReader;
use Reli\Lib\Elf\Parser\Elf64Parser;

final class ModuleEhFrameCache
{
    /** @var array<string, array<Fde>> module path => sorted FDE array */
    private array $cache = [];

    /** @var array<string, bool> modules that have been attempted but have no .eh_frame */
    private array $noEhFrame = [];

    private EhFrameParser $ehFrameParser;
    private Elf64Parser $elfParser;

    public function __construct()
    {
        $integer_reader = new LittleEndianReader();
        $this->ehFrameParser = new EhFrameParser($integer_reader);
        $this->elfParser = new Elf64Parser($integer_reader);
    }

    /**
     * @return array<Fde>|null
     */
    public function getFdesForModule(string $modulePath): ?array
    {
        if (isset($this->noEhFrame[$modulePath])) {
            return null;
        }

        if (isset($this->cache[$modulePath])) {
            return $this->cache[$modulePath];
        }

        return $this->loadModule($modulePath);
    }

    public function findFdeForAddress(string $modulePath, int $relativeAddress): ?Fde
    {
        $fdes = $this->getFdesForModule($modulePath);
        if ($fdes === null || $fdes === []) {
            return null;
        }

        // Binary search for the FDE containing the address
        $lo = 0;
        $hi = count($fdes) - 1;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            $fde = $fdes[$mid];
            if ($relativeAddress < $fde->initialLocation) {
                $hi = $mid - 1;
            } elseif ($relativeAddress >= $fde->initialLocation + $fde->addressRange) {
                $lo = $mid + 1;
            } else {
                return $fde;
            }
        }

        return null;
    }

    /**
     * @return array<Fde>|null
     */
    private function loadModule(string $modulePath): ?array
    {
        if (!is_file($modulePath)) {
            $this->noEhFrame[$modulePath] = true;
            return null;
        }

        $binary_data = file_get_contents($modulePath);
        if ($binary_data === false) {
            $this->noEhFrame[$modulePath] = true;
            return null;
        }

        $byte_reader = new StringByteReader($binary_data);

        // Check ELF magic
        if (strlen($binary_data) < 4
            || $byte_reader[0] !== 0x7f
            || $byte_reader[1] !== 0x45 // 'E'
            || $byte_reader[2] !== 0x4c // 'L'
            || $byte_reader[3] !== 0x46 // 'F'
        ) {
            $this->noEhFrame[$modulePath] = true;
            return null;
        }

        try {
            $elf_header = $this->elfParser->parseElfHeader($byte_reader);

            if ($elf_header->e_shnum === 0 || $elf_header->e_shoff->toInt() === 0) {
                $this->noEhFrame[$modulePath] = true;
                return null;
            }

            $section_headers = $this->elfParser->parseSectionHeader($byte_reader, $elf_header);
            $eh_frame_section = $section_headers->findSectionByName('.eh_frame');

            if ($eh_frame_section === null) {
                $this->noEhFrame[$modulePath] = true;
                return null;
            }

            $fdes = $this->ehFrameParser->parse(
                $byte_reader,
                $eh_frame_section->sh_offset->toInt(),
                $eh_frame_section->sh_size->toInt(),
                $eh_frame_section->sh_addr->toInt(),
            );

            // Sort by initial location for binary search
            usort($fdes, fn(Fde $a, Fde $b) => $a->initialLocation <=> $b->initialLocation);

            $this->cache[$modulePath] = $fdes;
            return $fdes;
        } catch (\Throwable) {
            $this->noEhFrame[$modulePath] = true;
            return null;
        }
    }
}
