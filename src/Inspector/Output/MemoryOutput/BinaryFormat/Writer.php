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
 * Sequential binary writer for the .rmem format.
 *
 * Usage:
 *   1. Construct with output file path
 *   2. Write sections in order via writeSection()
 *   3. Call finish() to write the header and TOC
 */
final class Writer
{
    /** @var resource */
    private $fh;

    /** @var list<array{name: string, offset: int, length: int, element_count: int}> */
    private array $toc = [];

    /** Current write position (starts after header placeholder) */
    private int $pos;

    public function __construct(string $path)
    {
        $fh = fopen($path, 'wb');
        if ($fh === false) {
            throw new \RuntimeException("Cannot open {$path} for writing");
        }
        $this->fh = $fh;

        // Reserve space for the header (will be rewritten in finish())
        fwrite($this->fh, str_repeat("\0", Format::HEADER_SIZE));
        $this->pos = Format::HEADER_SIZE;
    }

    /**
     * Write a complete section from a string buffer.
     *
     * @param string $name Section name (max 16 chars)
     * @param string $data Raw section bytes
     * @param int $element_count Number of logical rows/entries
     */
    public function writeSection(string $name, string $data, int $element_count): void
    {
        $offset = $this->pos;
        $length = strlen($data);
        fwrite($this->fh, $data);
        $this->pos += $length;

        $this->toc[] = [
            'name' => $name,
            'offset' => $offset,
            'length' => $length,
            'element_count' => $element_count,
        ];
    }

    /**
     * Write a section by streaming from a source file. Avoids loading
     * the entire section into memory, which matters for multi-GB
     * node/edge/location sections.
     *
     * @param string $name Section name (max 16 chars)
     * @param string $source_path Path to the temp file containing section data
     * @param int $element_count Number of logical rows/entries
     * @param int $copy_chunk Bytes per stream_copy_to_stream call
     */
    public function writeSectionFromFile(
        string $name,
        string $source_path,
        int $element_count,
        int $copy_chunk = 8 * 1024 * 1024,
    ): void {
        $source = fopen($source_path, 'rb');
        if ($source === false) {
            throw new \RuntimeException("Cannot open source file: {$source_path}");
        }

        $offset = $this->pos;
        $length = 0;
        while (!feof($source)) {
            $copied = stream_copy_to_stream($source, $this->fh, $copy_chunk);
            if ($copied === false || $copied === 0) {
                break;
            }
            $length += $copied;
        }
        fclose($source);
        $this->pos += $length;

        $this->toc[] = [
            'name' => $name,
            'offset' => $offset,
            'length' => $length,
            'element_count' => $element_count,
        ];
    }

    /**
     * Write a section via a callback that writes directly to the output
     * file handle. The callback receives the file handle and must return
     * the number of bytes written. This avoids materializing the entire
     * section in a PHP string.
     *
     * @param string $name Section name (max 16 chars)
     * @param callable(resource): int $writer_fn Callback: fn($fh) → bytes_written
     * @param int $element_count Number of logical rows/entries
     */
    public function writeSectionWithCallback(
        string $name,
        callable $writer_fn,
        int $element_count,
    ): void {
        $offset = $this->pos;
        $length = $writer_fn($this->fh);
        $this->pos += $length;

        $this->toc[] = [
            'name' => $name,
            'offset' => $offset,
            'length' => $length,
            'element_count' => $element_count,
        ];
    }

    /**
     * Finalize: write TOC, then rewind and write the header.
     */
    public function finish(): void
    {
        // Write TOC at current position
        $toc_offset = $this->pos;
        foreach ($this->toc as $entry) {
            // name: 16 bytes, null-padded
            $name_padded = str_pad($entry['name'], Format::TOC_NAME_SIZE, "\0");
            fwrite($this->fh, substr($name_padded, 0, Format::TOC_NAME_SIZE));
            // offset: uint64 LE
            fwrite($this->fh, pack('P', $entry['offset']));
            // length: uint64 LE
            fwrite($this->fh, pack('P', $entry['length']));
            // element_count: uint64 LE
            fwrite($this->fh, pack('P', $entry['element_count']));
        }

        // Rewind and write the header
        fseek($this->fh, 0);
        $header = Format::MAGIC;                                  // 4 bytes
        $header .= pack('V', Format::VERSION);                    // 4 bytes: version (uint32 LE)
        $header .= pack('V', Format::FLAG_LITTLE_ENDIAN);         // 4 bytes: flags (uint32 LE)
        $header .= pack('V', count($this->toc));                  // 4 bytes: section_count (uint32 LE)
        $header .= pack('P', $toc_offset);                        // 8 bytes: toc_offset (uint64 LE)
        // Pad to 64 bytes
        $header .= str_repeat("\0", Format::HEADER_SIZE - strlen($header));
        fwrite($this->fh, $header);

        fclose($this->fh);
    }
}
