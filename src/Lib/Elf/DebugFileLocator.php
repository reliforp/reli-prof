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

namespace Reli\Lib\Elf;

use Reli\Lib\ByteStream\ByteReaderInterface;
use Reli\Lib\ByteStream\IntegerByteSequence\LittleEndianReader;
use Reli\Lib\ByteStream\StringByteReader;
use Reli\Lib\Elf\Parser\Elf64Parser;
use Reli\Lib\Elf\Structure\Elf64\Elf64Note;
use Reli\Lib\Elf\Structure\Elf64\Elf64SectionHeaderTable;

final class DebugFileLocator
{
    /** @var string[] */
    private array $debugDirs;

    private Elf64Parser $elfParser;

    /** @var array<string, ?string> binary path => debug file path (cached) */
    private array $cache = [];

    /**
     * @param string[] $debugDirs
     */
    public function __construct(array $debugDirs = ['/usr/lib/debug'])
    {
        $this->debugDirs = $debugDirs;
        $this->elfParser = new Elf64Parser(new LittleEndianReader());
    }

    public function locate(string $binaryPath): ?string
    {
        if (array_key_exists($binaryPath, $this->cache)) {
            return $this->cache[$binaryPath];
        }

        $result = $this->doLocate($binaryPath);
        $this->cache[$binaryPath] = $result;
        return $result;
    }

    private function doLocate(string $binaryPath): ?string
    {
        if (!is_file($binaryPath)) {
            return null;
        }

        $binary = file_get_contents($binaryPath);
        if ($binary === false || strlen($binary) < 4) {
            return null;
        }

        $byte_reader = new StringByteReader($binary);

        // Check ELF magic
        if (
            $byte_reader[0] !== 0x7f || $byte_reader[1] !== 0x45
            || $byte_reader[2] !== 0x4c || $byte_reader[3] !== 0x46
        ) {
            return null;
        }

        try {
            $elf_header = $this->elfParser->parseElfHeader($byte_reader);
            if ($elf_header->e_shnum === 0 || $elf_header->e_shoff->toInt() === 0) {
                return null;
            }
            $section_headers = $this->elfParser->parseSectionHeader($byte_reader, $elf_header);

            // 1. Try build-id first (most reliable, used by Debian/Ubuntu)
            $result = $this->searchByBuildIdSection($byte_reader, $section_headers);
            if ($result !== null) {
                return $result;
            }

            // 2. Try .gnu_debuglink
            $result = $this->searchByDebugLinkSection($byte_reader, $section_headers, $binaryPath);
            if ($result !== null) {
                return $result;
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function searchByBuildIdSection(
        ByteReaderInterface $data,
        Elf64SectionHeaderTable $sections,
    ): ?string {
        $section = $sections->findSectionByName('.note.gnu.build-id');
        if ($section === null) {
            return null;
        }

        $notes = $this->elfParser->parseNoteSection($data, $section);
        foreach ($notes as $note) {
            // Build-id notes have name "GNU\0" and type NT_GNU_BUILD_ID (3)
            if (rtrim($note->name, "\0") === 'GNU' && $note->type === Elf64Note::NT_GNU_BUILD_ID) {
                $build_id = bin2hex($note->desc);
                return $this->searchByBuildId($build_id);
            }
        }

        return null;
    }

    private function searchByBuildId(string $buildIdHex): ?string
    {
        if (strlen($buildIdHex) < 4) {
            return null;
        }

        $prefix = substr($buildIdHex, 0, 2);
        $suffix = substr($buildIdHex, 2);

        foreach ($this->debugDirs as $debug_dir) {
            $path = "{$debug_dir}/.build-id/{$prefix}/{$suffix}.debug";
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function searchByDebugLinkSection(
        ByteReaderInterface $data,
        Elf64SectionHeaderTable $sections,
        string $binaryPath,
    ): ?string {
        $section = $sections->findSectionByName('.gnu_debuglink');
        if ($section === null) {
            return null;
        }

        $offset = $section->sh_offset->toInt();
        $size = $section->sh_size->toInt();

        if ($size < 5) { // minimum: 1 byte name + null + 3 padding + 4 CRC
            return null;
        }

        // Read null-terminated filename
        $filename = '';
        for ($i = 0; $i < $size - 4; $i++) {
            $byte = $data[$offset + $i];
            if ($byte === 0) {
                break;
            }
            $filename .= chr($byte);
        }

        if ($filename === '') {
            return null;
        }

        return $this->searchByDebugLink($filename, dirname($binaryPath));
    }

    private function searchByDebugLink(string $filename, string $binaryDir): ?string
    {
        // GDB-compatible search order
        $candidates = [
            "{$binaryDir}/{$filename}",
            "{$binaryDir}/.debug/{$filename}",
        ];

        foreach ($this->debugDirs as $debug_dir) {
            $candidates[] = "{$debug_dir}{$binaryDir}/{$filename}";
        }

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
