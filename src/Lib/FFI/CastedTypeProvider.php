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

namespace Reli\Lib\FFI;

use FFI\CData;
use Reli\Lib\PhpInternals\CastedCData;

interface CastedTypeProvider
{
    /** @return CastedCData<CData> */
    public function readAs(string $ctype_name, CData $buffer): CastedCData;
}
