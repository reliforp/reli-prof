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

use Reli\Lib\PhpInternals\CastedCData;

final class ZendMmPageMap
{
    /** @param CastedCData<\FFI\PhpInternals\zend_mm_page_map> $cdata */
    public function __construct(
        private CastedCData $cdata,
    ) {
    }

    public function getPageInfo(
        int $page_index
    ): ZendMmPageInfoSmall|ZendMmPageInfoLarge|ZendMmPageInfoFree {
        $info = $this->cdata->casted[$page_index];
        if ($info === 0) {
            return new ZendMmPageInfoFree();
        } elseif ($info & 0x80000000) {
            return new ZendMmPageInfoSmall($info);
        } elseif ($info & 0x40000000) {
            return new ZendMmPageInfoLarge($info);
        }
        throw new \LogicException();
    }
}
