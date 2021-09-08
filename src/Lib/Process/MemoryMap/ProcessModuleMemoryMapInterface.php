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

namespace PhpProfiler\Lib\Process\MemoryMap;

interface ProcessModuleMemoryMapInterface
{
    public function getBaseAddress(): int;

    public function getMemoryAddressFromOffset(int $offset): int;

    public function isInRange(int $address): bool;
}
