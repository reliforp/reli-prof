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

namespace Reli\Lib\PhpInternals\Types\Zend;

class ZendMmPageInfoLarge
{
    public const PAGE_SIZE = (4 * 1024);

    public function __construct(
        public int $info,
    ) {
    }

    public function getLargePagesCount(): int
    {
        return $this->info & 0x000003ff;
    }

    public function getPagesSizeInBytes(): int
    {
        return self::PAGE_SIZE * $this->getLargePagesCount();
    }

    public function isAligned(int $address): bool
    {
        return ($address & (self::PAGE_SIZE - 1)) === 0;
    }
}
