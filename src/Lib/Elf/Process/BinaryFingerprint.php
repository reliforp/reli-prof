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

final class BinaryFingerprint
{
    public function __construct(
        private string $fingerprint
    ) {
    }

    public static function fromProcessModuleMemoryMap(
        ProcessModuleMemoryMap $process_module_memory_map
    ): self {
        return new self(
            join(
                '_',
                [
                    $process_module_memory_map->getDeviceId(),
                    $process_module_memory_map->getInodeNumber(),
                    $process_module_memory_map->getModuleName(),
                ]
            )
        );
    }

    public function __toString(): string
    {
        return $this->fingerprint;
    }
}
