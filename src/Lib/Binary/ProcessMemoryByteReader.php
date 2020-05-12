<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpProfiler\Lib\Binary;

use PhpProfiler\Lib\Process\MemoryMap\ProcessMemoryArea;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderInterface;

class ProcessMemoryByteReader implements ByteReaderInterface
{
    use ByteReaderDisableWriteAccessTrait;

    private const PAGE_SIZE = 8192;

    private MemoryReaderInterface $memory_reader;

    /** @var CDataByteReader[] */
    private array $pages = [];
    private int $pid;
    /** @var ProcessMemoryArea[] */
    private array $memory_area;
    private bool $is_address = false;

    /**
     * ProcessMemoryByteReader constructor.
     * @param MemoryReaderInterface $memory_reader
     * @param int $pid
     * @param ProcessMemoryArea[] $memory_area
     */
    public function __construct(MemoryReaderInterface $memory_reader, int $pid, array $memory_area)
    {
        $this->memory_reader = $memory_reader;
        $this->pid = $pid;
        $this->memory_area = $memory_area;
    }

    public function offsetExists($offset): bool
    {
        return true;
    }

    public function useMemoryAddress(bool $is_address): void
    {
        $this->is_address = $is_address;
    }

    public function getBegin(): int
    {
        $begin = PHP_INT_MAX;
        foreach ($this->memory_area as $memory_area) {
            $begin = min($begin, hexdec($memory_area->begin));
        }
        return $begin;
    }

    public function offsetGet($offset): int
    {
        if (!$this->is_address) {
            $offset = $this->getMemoryAddressFromOffset($offset);
        }
        $page = (int)floor($offset / self::PAGE_SIZE);
        if (!isset($this->pages[$page])) {
            $this->pages[$page] = new CDataByteReader(
                $this->memory_reader->read(
                    $this->pid,
                    max($this->getBegin(), $page * self::PAGE_SIZE),
                    self::PAGE_SIZE
                )
            );
        }
        $diff = 0;
        if ($page * self::PAGE_SIZE < $this->getBegin()) {
            $diff = $this->getBegin() - $page * self::PAGE_SIZE;
        }
        return $this->pages[$page][($offset % self::PAGE_SIZE) - $diff];
    }

    private function getMemoryAddressFromOffset(int $offset): int
    {
        $ranges = [];
        foreach ($this->memory_area as $memory_area) {
            $ranges[hexdec($memory_area->file_offset)] = hexdec($memory_area->begin);
        }
        ksort($ranges);
        $file_offset_decided = 0;
        foreach ($ranges as $file_offset => $memory_begin) {
            if ($file_offset <= $offset) {
                $file_offset_decided = $file_offset;
            }
        }
        return $ranges[$file_offset_decided] + ($offset - $file_offset_decided);
    }

    public function createSliceAsString(int $offset, int $size): string
    {
        $result = '';
        for ($i = $offset; $i < ($offset + $size); $i++) {
            $result .= chr($this[$i]);
        }
        return $result;
    }
}
