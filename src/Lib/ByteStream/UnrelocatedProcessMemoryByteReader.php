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

namespace PhpProfiler\Lib\ByteStream;

use PhpProfiler\Lib\Process\MemoryMap\ProcessModuleMemoryMapInterface;

final class UnrelocatedProcessMemoryByteReader implements ByteReaderInterface
{
    use ByteReaderDisableWriteAccessTrait;

    /**
     * UnrelocatedProcessMemoryByteReader constructor.
     */
    public function __construct(
        private ByteReaderInterface $byte_reader,
        private ProcessModuleMemoryMapInterface $module_memory_map
    ) {
    }

    public function offsetExists($offset): bool
    {
        return isset($this->byte_reader[$this->module_memory_map->getMemoryAddressFromOffset($offset)]);
    }

    public function offsetGet($offset): int
    {
        return $this->byte_reader[$this->module_memory_map->getMemoryAddressFromOffset($offset)];
    }

    public function createSliceAsString(int $offset, int $size): string
    {
        return $this->byte_reader->createSliceAsString(
            $this->module_memory_map->getMemoryAddressFromOffset($offset),
            $size
        );
    }
}
