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

final class ZendClassEntryInfo
{
    public ZendClassEntryInfoUser $user;

    /** @param CastedCData<\FFI\PhpInternals\zend_class_entry_info> $cdata */
    public function __construct(
        private CastedCData $cdata,
    ) {
        $this->user = new ZendClassEntryInfoUser(
            $this->cdata->createSubView($this->cdata->casted->user)
        );
    }
}
