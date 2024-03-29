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

namespace Reli\Lib\ByteStream;

use Reli\Lib\Process\MemoryMap\ProcessModuleMemoryMapInterface;

final class UnrelocatedProcessMemoryByteReader implements ByteReaderInterface
{
    use ByteReaderDisableWriteAccessTrait;

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
