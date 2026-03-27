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

namespace Reli\Lib\Elf\Process;

use Reli\Lib\Process\MemoryMap\ProcessModuleMemoryMap;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;

final class BinaryFingerprintCreator
{
    private const ELF_HEADER_SIZE = 64;

    public function __construct(
        private MemoryReaderInterface $memory_reader,
    ) {
    }

    public function createFromProcessModuleMemoryMap(
        int $pid,
        ProcessModuleMemoryMap $process_module_memory_map,
    ): BinaryFingerprint {
        $base_address = $process_module_memory_map->getBaseAddress();
        try {
            $header_cdata = $this->memory_reader->read($pid, $base_address, self::ELF_HEADER_SIZE);
            $header_bytes = '';
            for ($i = 0; $i < self::ELF_HEADER_SIZE; $i++) {
                /** @var int $byte */
                $byte = $header_cdata[$i];
                $header_bytes .= chr($byte);
            }
            return BinaryFingerprint::fromProcessModuleMemoryMapAndElfHeader(
                $process_module_memory_map,
                $header_bytes,
            );
        } catch (\Throwable) {
            return BinaryFingerprint::fromProcessModuleMemoryMap($process_module_memory_map);
        }
    }
}
