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

namespace PhpProfiler\Lib\FFI;

use FFI\CData;
use PhpProfiler\Lib\PhpInternals\CastedCData;

/**
 * @template T
 */
interface CastedTypeProvider
{
    /** @return CastedCData<CData> */
    public function readAs(string $ctype_name, CData $buffer): CastedCData;
}
