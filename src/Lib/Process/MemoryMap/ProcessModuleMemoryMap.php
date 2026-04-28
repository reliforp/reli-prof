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

namespace Reli\Lib\Process\MemoryMap;

final class ProcessModuleMemoryMap implements ProcessModuleMemoryMapInterface
{
    private ?int $base_address = null;
    /** @var array<int,int>|null  */
    private ?array $sorted_offset_to_memory_map = null;

    /** @param ProcessMemoryArea[] $memory_areas */
    public function __construct(
        private array $memory_areas
    ) {
    }

    #[\Override]
    public function getBaseAddress(): int
    {
        $this->assertNotEmpty(__FUNCTION__);
        if (!isset($this->base_address)) {
            $base_address = PHP_INT_MAX;
            foreach ($this->memory_areas as $memory_area) {
                $base_address = min($base_address, hexdec($memory_area->begin));
            }
            $this->base_address = $base_address;
        }
        return $this->base_address;
    }

    #[\Override]
    public function getMemoryAddressFromOffset(int $offset): int
    {
        $this->assertNotEmpty(__FUNCTION__);
        $ranges = $this->getSortedOffsetToMemoryAreaMap();
        $file_offset_decided = 0;
        foreach ($ranges as $file_offset => $_memory_begin) {
            if ($file_offset <= $offset) {
                $file_offset_decided = $file_offset;
            }
        }
        return $ranges[$file_offset_decided] + ($offset - $file_offset_decided);
    }

    #[\Override]
    public function isInRange(int $address): bool
    {
        foreach ($this->memory_areas as $memory_area) {
            if ($memory_area->isInRange($address)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int, int> */
    private function getSortedOffsetToMemoryAreaMap(): array
    {
        if (!isset($this->sorted_offset_to_memory_map)) {
            $ranges = [];
            foreach ($this->memory_areas as $memory_area) {
                $ranges[hexdec($memory_area->file_offset)] = hexdec($memory_area->begin);
            }
            ksort($ranges);
            $this->sorted_offset_to_memory_map = $ranges;
        }
        return $this->sorted_offset_to_memory_map;
    }

    #[\Override]
    public function getDeviceId(): string
    {
        $this->assertNotEmpty(__FUNCTION__);
        return $this->memory_areas[0]->device_id;
    }

    #[\Override]
    public function getInodeNumber(): int
    {
        $this->assertNotEmpty(__FUNCTION__);
        return $this->memory_areas[0]->inode_num;
    }

    #[\Override]
    public function getModuleName(): string
    {
        $this->assertNotEmpty(__FUNCTION__);
        return $this->memory_areas[0]->name;
    }

    /**
     * Refuse access on a regex-mismatched (empty) map. The original symptom
     * was "Undefined array key 0" / "Attempt to read property on null"
     * warnings followed by a TypeError from getDeviceId() when a too-broad
     * regex picked up a non-PHP candidate. Surfacing as a single catchable
     * \RuntimeException lets the existing catch (\Throwable) sites in
     * BinaryFingerprintCreator / ProcessDescriptorRetriever continue
     * filtering those candidates out without reading their entire
     * executable file -- accessors are gated rather than the constructor
     * because the construct-then-fingerprint flow relies on the
     * catch (\Throwable) downstream to reject the candidate.
     */
    private function assertNotEmpty(string $caller): void
    {
        if ($this->memory_areas === []) {
            throw new \RuntimeException(
                sprintf(
                    'ProcessModuleMemoryMap::%s called on a map with no memory areas;'
                    . ' the regex used to filter /proc/{pid}/maps matched nothing',
                    $caller,
                ),
            );
        }
    }
}
