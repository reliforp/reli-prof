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

namespace Reli\Inspector\Output\MemoryOutput\BinaryFormat;

/**
 * Reader for the .rmem binary format.
 *
 * Stage 1: fopen + fread. Stage 2 will add real mmap via FFI.
 */
final class Reader
{
    /** @var array<string, array{offset: int, length: int, element_count: int}> */
    private array $toc = [];

    private string $fileData;

    private StringDict $stringDict;

    private function __construct()
    {
    }

    public static function open(string $path): self
    {
        $data = file_get_contents($path);
        if ($data === false) {
            throw new \RuntimeException("Cannot read {$path}");
        }

        $reader = new self();
        $reader->fileData = $data;
        $reader->parseHeader();
        $reader->loadStringDict();
        return $reader;
    }

    private function parseHeader(): void
    {
        if (strlen($this->fileData) < Format::HEADER_SIZE) {
            throw new \RuntimeException('File too small for rmem header');
        }

        $magic = substr($this->fileData, 0, 4);
        if ($magic !== Format::MAGIC) {
            throw new \RuntimeException('Invalid rmem magic: ' . bin2hex($magic));
        }

        $version = unpack('V', $this->fileData, 4)[1];
        if ($version !== Format::VERSION) {
            throw new \RuntimeException("Unsupported rmem version: {$version}");
        }

        $flags = unpack('V', $this->fileData, 8)[1];
        if (($flags & Format::FLAG_LITTLE_ENDIAN) === 0) {
            throw new \RuntimeException('Big-endian rmem files are not supported');
        }

        $section_count = unpack('V', $this->fileData, 12)[1];
        $toc_offset = unpack('P', $this->fileData, 16)[1];

        // Parse TOC
        $pos = (int)$toc_offset;
        for ($i = 0; $i < $section_count; $i++) {
            $name = rtrim(substr($this->fileData, $pos, Format::TOC_NAME_SIZE), "\0");
            $pos += Format::TOC_NAME_SIZE;
            $offset = unpack('P', $this->fileData, $pos)[1];
            $pos += 8;
            $length = unpack('P', $this->fileData, $pos)[1];
            $pos += 8;
            $element_count = unpack('P', $this->fileData, $pos)[1];
            $pos += 8;

            $this->toc[$name] = [
                'offset' => (int)$offset,
                'length' => (int)$length,
                'element_count' => (int)$element_count,
            ];
        }
    }

    private function loadStringDict(): void
    {
        if (!isset($this->toc[Format::SECTION_STRING_DICT])) {
            $this->stringDict = new StringDict();
            return;
        }
        $entry = $this->toc[Format::SECTION_STRING_DICT];
        $data = substr($this->fileData, $entry['offset'], $entry['length']);
        $this->stringDict = StringDict::deserialize($data);
    }

    public function getStringDict(): StringDict
    {
        return $this->stringDict;
    }

    public function hasSection(string $name): bool
    {
        return isset($this->toc[$name]);
    }

    /**
     * Get raw section data as a string buffer.
     */
    public function getSectionData(string $name): string
    {
        if (!isset($this->toc[$name])) {
            throw new \RuntimeException("Section not found: {$name}");
        }
        $entry = $this->toc[$name];
        return substr($this->fileData, $entry['offset'], $entry['length']);
    }

    public function getSectionElementCount(string $name): int
    {
        if (!isset($this->toc[$name])) {
            return 0;
        }
        return $this->toc[$name]['element_count'];
    }

    public function getSectionOffset(string $name): int
    {
        if (!isset($this->toc[$name])) {
            throw new \RuntimeException("Section not found: {$name}");
        }
        return $this->toc[$name]['offset'];
    }

    public function getSectionLength(string $name): int
    {
        if (!isset($this->toc[$name])) {
            return 0;
        }
        return $this->toc[$name]['length'];
    }

    /**
     * Get the raw file data buffer. Used by substrate loaders that
     * want to FFI::cast directly into the buffer.
     */
    public function getFileData(): string
    {
        return $this->fileData;
    }
}
