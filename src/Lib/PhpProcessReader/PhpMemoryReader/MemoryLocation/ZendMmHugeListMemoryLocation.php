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

use Reli\Lib\PhpInternals\Types\Zend\ZendMmHugeList;
use Reli\Lib\Process\MemoryLocation;

final class ZendMmHugeListMemoryLocation extends MemoryLocation
{
    public static function fromZendMmHugeList(ZendMmHugeList $zend_mm_huge_list): self
    {
        return new self(
            $zend_mm_huge_list->getPointer()->address,
            $zend_mm_huge_list->getPointer()->size,
        );
    }
}
