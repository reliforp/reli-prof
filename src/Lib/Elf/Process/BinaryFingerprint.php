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

use Reli\Lib\Process\MemoryMap\ProcessModuleMemoryMapInterface;

final class BinaryFingerprint
{
    public function __construct(
        private string $fingerprint
    ) {
    }

    public static function fromProcessModuleMemoryMap(
        ProcessModuleMemoryMapInterface $process_module_memory_map
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

    /**
     * Create a fingerprint that includes actual ELF header content from the binary.
     *
     * Filesystem metadata (device_id + inode) alone is not sufficient for reliable
     * cache keying in container environments: Docker's overlayfs can assign the same
     * device_id and inode to different binaries across different images (e.g. php:8.3
     * and php:8.3-zts). Including the ELF header content ensures that different
     * binaries always produce different fingerprints, even when their filesystem
     * metadata happens to collide.
     *
     * @param string $elf_header_bytes Raw bytes from the ELF header (typically first 64 bytes)
     */
    public static function fromProcessModuleMemoryMapAndElfHeader(
        ProcessModuleMemoryMapInterface $process_module_memory_map,
        string $elf_header_bytes,
    ): self {
        return new self(
            join(
                '_',
                [
                    $process_module_memory_map->getDeviceId(),
                    $process_module_memory_map->getInodeNumber(),
                    $process_module_memory_map->getModuleName(),
                    bin2hex($elf_header_bytes),
                ]
            )
        );
    }

    public function getCacheKey(): string
    {
        return hash('sha256', $this->fingerprint);
    }

    public function __toString(): string
    {
        return $this->fingerprint;
    }
}
