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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation;

use Reli\Lib\PhpInternals\Types\Zend\ZendMmChunk;
use Reli\Lib\PhpInternals\Types\Zend\ZendMmHugeList;
use Reli\Lib\PhpInternals\Types\Zend\ZendMmPageInfoLarge;
use Reli\Lib\PhpInternals\Types\Zend\ZendMmPageInfoSmall;
use Reli\Lib\Process\MemoryLocation;

class ZendMmChunkMemoryLocation extends MemoryLocation
{
    public function __construct(
        int $address,
        int $size,
        private ?ZendMmChunk $zend_mm_chunk = null,
    ) {
        parent::__construct($address, $size);
    }

    public static function fromZendMmChunk(ZendMmChunk $zend_mm_chunk): self
    {
        return new self(
            $zend_mm_chunk->getPointer()->address,
            ZendMmChunk::SIZE,
            $zend_mm_chunk,
        );
    }

    public static function fromZendMmHugeList(ZendMmHugeList $zend_mm_huge_list): self
    {
        return new self(
            $zend_mm_huge_list->ptr,
            $zend_mm_huge_list->size,
        );
    }

    public function getOverhead(MemoryLocation $memory_location): ?ZendMmOverheadMemoryLocation
    {
        if (is_null($this->zend_mm_chunk)) {
            return null;
        }
        if ($memory_location instanceof ZendArrayTableOverheadMemoryLocation) {
            $memory_location = new MemoryLocation(
                $memory_location->used_location->address,
                $memory_location->size + $memory_location->used_location->size,
            );
        }

        $page = $this->zend_mm_chunk->getPageOfAddress($memory_location->address);
        $page_info = $this->zend_mm_chunk->map->getPageInfo($page);
        if ($page_info instanceof ZendMmPageInfoSmall) {
            if ($memory_location->size < $page_info->getBinSize()) {
                return new ZendMmOverheadMemoryLocation(
                    $memory_location->address + $memory_location->size,
                    $page_info->getBinSize() - $memory_location->size,
                );
            } else {
                return null;
            }
        } elseif ($page_info instanceof ZendMmPageInfoLarge) {
            if (
                $page_info->isAligned($memory_location->address)
                and $memory_location->size < $page_info->getPagesSizeInBytes()
            ) {
                return new ZendMmOverheadMemoryLocation(
                    $memory_location->address + $memory_location->size,
                    $page_info->getPagesSizeInBytes() - $memory_location->size,
                );
            } else {
                return null;
            }
        }
        return null;
    }
}
