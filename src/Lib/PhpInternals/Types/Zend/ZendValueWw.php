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

use FFI\CData;
use FFI\PhpInternals\zend_value_ww;

class ZendValueWw
{
    public int $w1;
    public int $w2;

    /** @param zend_value_ww $cdata */
    public function __construct(
        private CData $cdata,
    ) {
        $this->w1 = $this->cdata->w1;
        $this->w2 = $this->cdata->w2;
    }
}
