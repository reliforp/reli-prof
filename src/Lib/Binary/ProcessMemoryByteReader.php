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

namespace PhpProfiler\Lib\Binary;

use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderInterface;

final class ProcessMemoryByteReader implements ByteReaderInterface
{
    use ByteReaderDisableWriteAccessTrait;

    private const PAGE_SIZE = 8192;

    private MemoryReaderInterface $memory_reader;

    /** @var CDataByteReader[] */
    private array $pages = [];
    private int $pid;
    private int $base_address;

    /**
     * ProcessMemoryByteReader constructor.
     * @param MemoryReaderInterface $memory_reader
     * @param int $pid
     * @param int $base_address
     */
    public function __construct(MemoryReaderInterface $memory_reader, int $pid, int $base_address)
    {
        $this->memory_reader = $memory_reader;
        $this->pid = $pid;
        $this->base_address = $base_address;
    }

    public function offsetExists($offset): bool
    {
        return true;
    }

    public function offsetGet($offset): int
    {
        $page = (int)floor($offset / self::PAGE_SIZE);
        if (!isset($this->pages[$page])) {
            $this->pages[$page] = new CDataByteReader(
                $this->memory_reader->read(
                    $this->pid,
                    max($this->base_address, $page * self::PAGE_SIZE),
                    self::PAGE_SIZE
                )
            );
        }
        $diff = 0;
        if ($page * self::PAGE_SIZE < $this->base_address) {
            $diff = $this->base_address - $page * self::PAGE_SIZE;
        }
        return $this->pages[$page][($offset % self::PAGE_SIZE) - $diff];
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
