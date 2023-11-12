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

class ZendMmPageInfoSmall
{
    public function __construct(
        public int $info,
    ) {
    }

    public function getBinNum(): int
    {
        return ($this->info & 0x0000001f);
    }

    public function getBinSize(): int
    {
        return ZendMmBinsInfo::getSize($this->getBinNum());
    }

    public function getBinElements(): int
    {
        return ZendMmBinsInfo::getCount($this->getBinNum());
    }

    public function getBinPages(): int
    {
        return ZendMmBinsInfo::getPages($this->getBinNum());
    }
}
